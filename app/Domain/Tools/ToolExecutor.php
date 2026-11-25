<?php

namespace App\Domain\Tools;

use App\Domain\Assistant\AssistantContext;
use App\Models\AssistantToolCall;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single door every tool call goes through.
 *
 * Nothing may reach a tool except through here, because this is where the three
 * guarantees live: the user is authorised for this specific call, the call is
 * recorded whether it succeeded or not, and everything the tool causes is
 * attributed to the assistant.
 *
 * A tool that throws is turned into a failed result rather than an exception.
 * The assistant is a client of the application like any other, and a client
 * sending a bad request gets an error back and another try.
 */
class ToolExecutor
{
    /** How many lines of a changed preview the refusal repeats before it stops. */
    private const CHANGED_LINES_SHOWN = 5;

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly AssistantContext $context,
    ) {}

    public function run(ToolCall $call): ToolResult
    {
        $started_at = microtime(true);
        $tool = $this->registry->find($call->name);

        if (!$tool) {
            $unknown = ToolResult::failed('Onbekende tool: ' . $call->name);

            return $this->record($call, $unknown, 'unknown_tool', $started_at);
        }

        /**
         * An approval is read before anything else, because what it carries is
         * what will run: the sealed arguments are the ones authorised and
         * checked, never the ones that happened to come along with the click.
         */
        $approval = $tool->requiresConfirmation()
            ? ConfirmationToken::decode($call->confirmation_token, $call->user)
            : null;

        if ($approval !== null && $approval->tool !== $call->name) {
            $approval = null;
        }

        if ($approval !== null) {
            $call = $call->withArguments($approval->arguments)->inConversation($approval->conversation);
        }

        /**
         * A policy that throws must deny, not escape. Letting it propagate would
         * take down the whole turn on what is only ever one failed capability,
         * and an authorisation check that errored has not said yes.
         */
        try {
            $permitted = $tool->authorize($call->user, $call->arguments);
        } catch (Throwable $e) {
            Log::error('Autorisatie van tool mislukt', ['tool' => $call->name, 'exception' => $e]);

            return $this->record($call, ToolResult::denied(), 'denied', $started_at);
        }

        if (!$permitted) {
            return $this->record($call, ToolResult::denied(), 'denied', $started_at);
        }

        /**
         * Checked after authorisation, so a tool somebody may not use gives away
         * nothing about its arguments, and before confirmation, so nobody is ever
         * shown a proposal built out of values the tool could not read.
         */
        $complaint = ArgumentCheck::against($tool->inputSchema(), $call->arguments);

        if ($complaint !== null) {
            return $this->record($call, ToolResult::failed($complaint), 'invalid_arguments', $started_at);
        }

        /**
         * Confirmation is declared by the tool but enforced here, never by asking
         * the model to be polite.
         *
         * Without an approval this stops and asks for one, handing back a token
         * with the arguments sealed inside it. What the person then agrees to is
         * exactly what runs: the arguments are read back out of the token rather
         * than taken from the call, so a second attempt cannot quietly differ
         * from the one that was read and approved. What the person was shown is
         * sealed in as well and worked out again on the click, so the same
         * arguments cannot quietly mean more than they did when the button was
         * shown.
         */
        if ($tool->requiresConfirmation()) {
            if ($approval === null) {
                return $this->propose($tool, $call, $started_at);
            }

            if (!ConfirmationToken::claim($call->confirmation_token)) {
                return $this->record(
                    $call,
                    ToolResult::failed(
                        'Deze bevestiging is al uitgevoerd. Kijk of het gelukt is voordat je het opnieuw doet.'
                    ),
                    'already_confirmed',
                    $started_at,
                );
            }

            $seal = $this->sealOf($tool, $call);

            if ($seal instanceof ToolResult) {
                return $this->record($call, $seal, 'error', $started_at);
            }

            $changed = $seal === null ? null : $this->changedSinceApproval($tool, $call, $approval, $seal);

            if ($changed !== null) {
                return $this->record($call, $changed[0], $changed[1], $started_at);
            }
        }

        $result = $this->safely($call, fn () => $tool->execute($call));

        return $this->record($call, $result, $result->is_error ? 'error' : 'ok', $started_at);
    }

    /** The button: what would happen, with the arguments and what was shown sealed into its token. */
    private function propose(Tool $tool, ToolCall $call, float $started_at): ToolResult
    {
        $refusal = $tool instanceof ChecksBeforeConfirming
            ? $this->safely($call, fn () => $tool->refusalBeforeConfirming($call))
            : null;

        if ($refusal !== null) {
            return $this->record($call, $refusal, 'refused_before_confirmation', $started_at);
        }

        $preview = $tool instanceof Confirmable ? $this->safely($call, fn () => $tool->previewOf($call)) : null;

        if ($preview instanceof ToolResult) {
            return $this->record($call, $preview, 'error', $started_at);
        }

        $seal = $tool instanceof SealsWhatWasAgreed ? $this->sealOf($tool, $call) : $preview;

        if ($seal instanceof ToolResult) {
            return $this->record($call, $seal, 'error', $started_at);
        }

        return $this->record(
            $call,
            ToolResult::ok(
                [
                    'status' => 'bevestiging_nodig',
                    'preview' => $preview ?? 'Deze actie wacht op bevestiging.',
                    'proposed' => $call->arguments,
                    'confirmation_token' => ConfirmationToken::for(
                        $call->name,
                        $call->arguments,
                        $call->user,
                        $call->conversation,
                        $seal,
                    )->encoded(),
                    'note' => 'Er is nog niets gewijzigd. Vertel de gebruiker precies wat je wilt doen '
                        . 'en vraag om bevestiging; het systeem laat de knop zien.',
                ],
                'Wacht op bevestiging.',
            ),
            'confirmation_required',
            $started_at,
        );
    }

    /**
     * What the approval is sealed with: the facts the person decided on when
     * the tool names them, otherwise the whole preview; nothing for a tool
     * that shows none. A tool that throws working it out gives a failed result.
     */
    private function sealOf(Tool $tool, ToolCall $call): string|ToolResult|null
    {
        return match (true) {
            $tool instanceof SealsWhatWasAgreed => $this->safely($call, fn () => $tool->sealOf($call)),
            $tool instanceof Confirmable => $this->safely($call, fn () => $tool->previewOf($call)),
            default => null,
        };
    }

    /**
     * Why the click may not run what the button said, with the outcome to
     * record, or nothing when it may.
     *
     * The seal is worked out again from the records as they are now and
     * compared with the one that was approved. When they differ the person
     * agreed to something else: a contract added since, a price that moved.
     * A tool that checks before confirming says why first, if it now refuses
     * outright, because "product #12 is no longer in the catalogue" helps more
     * than "something changed".
     *
     * @return ?array{0: ToolResult, 1: string}
     */
    private function changedSinceApproval(Tool $tool, ToolCall $call, ConfirmationToken $approval, string $seal): ?array
    {
        if ($approval->showed($seal)) {
            return null;
        }

        $refusal = $tool instanceof ChecksBeforeConfirming
            ? $this->safely($call, fn () => $tool->refusalBeforeConfirming($call))
            : null;

        if ($refusal !== null) {
            return [$refusal, 'changed_since_confirmation'];
        }

        $preview = $tool instanceof SealsWhatWasAgreed ? $this->safely($call, fn () => $tool->previewOf($call)) : $seal;

        if ($preview instanceof ToolResult) {
            return [$preview, 'error'];
        }

        return [
            ToolResult::failed(
                'Wat je bevestigde klopt niet meer: wat deze actie zou doen is intussen gewijzigd. Nu zou het zijn: '
                    . $this->shortened($preview) . "\nVraag het opnieuw."
            ),
            'changed_since_confirmation',
        ];
    }

    /**
     * The first lines of a preview, and how many there are. The refusal ends
     * up as the result line under the button, and a selection of hundreds of
     * contracts would otherwise be repeated there in full.
     */
    private function shortened(string $preview): string
    {
        $lines = explode("\n", trim($preview));
        $hidden = count($lines) - 1 - self::CHANGED_LINES_SHOWN;

        if ($hidden <= 0) {
            return implode("\n", $lines);
        }

        return implode("\n", array_slice($lines, 0, 1 + self::CHANGED_LINES_SHOWN))
            . "\n… en nog " . $hidden . ' regels';
    }

    /**
     * Tool code, run as the person who asked, with whatever it throws kept in
     * the log. A driver's own words carry table and column names, and they
     * reach the model, which relays them to somebody who asked when a boiler
     * was last serviced.
     *
     * Whatever the work returns comes back as it is; whatever it throws comes
     * back as a failed result.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn|ToolResult
     */
    private function safely(ToolCall $call, Closure $work): mixed
    {
        try {
            return $this->context->run($call->user, $work);
        } catch (Throwable $e) {
            Log::error('Tool mislukt', ['tool' => $call->name, 'exception' => $e]);

            return ToolResult::failed('Deze tool liep vast. Probeer het anders te vragen of gebruik een andere tool.');
        }
    }

    /**
     * The audit trail must never be the reason a call fails, so a write that goes
     * wrong is reported and swallowed. The activity log takes the same position
     * for the same reason.
     */
    private function record(ToolCall $call, ToolResult $result, string $outcome, float $started_at): ToolResult
    {
        try {
            AssistantToolCall::create([
                'user_id' => $call->user->id,
                'tool' => $call->name,
                'external_id' => $call->external_id,
                'arguments' => $call->arguments,
                'outcome' => $outcome,
                'result' => mb_substr($result->toModelContent(), 0, 4000),
                'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
            ]);
        } catch (Throwable $e) {
            Log::error('Kon toolaanroep niet vastleggen', ['tool' => $call->name, 'exception' => $e]);
        }

        return $result;
    }
}
