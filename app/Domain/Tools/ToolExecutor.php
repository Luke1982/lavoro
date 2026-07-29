<?php

namespace App\Domain\Tools;

use App\Domain\Assistant\AssistantContext;
use App\Models\AssistantToolCall;
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
         * Confirmation is declared by the tool but enforced here, never by asking
         * the model to be polite.
         *
         * Without an approval this stops and asks for one, handing back a token
         * with the arguments sealed inside it. What the person then agrees to is
         * exactly what runs: the arguments are read back out of the token rather
         * than taken from the call, so a second attempt cannot quietly differ
         * from the one that was read and approved.
         */
        if ($tool->requiresConfirmation()) {
            $approval = ConfirmationToken::decode($call->confirmation_token, $call->user);

            if ($approval === null || $approval->tool !== $call->name) {
                return $this->record(
                    $call,
                    ToolResult::ok(
                        [
                            'status' => 'bevestiging_nodig',
                            'preview' => $tool instanceof Confirmable
                                ? $tool->previewOf($call)
                                : 'Deze actie wacht op bevestiging.',
                            'proposed' => $call->arguments,
                            'confirmation_token' => ConfirmationToken::for($call->name, $call->arguments, $call->user)->encoded(),
                            'note' => 'Er is nog niets gewijzigd. Vertel de gebruiker precies wat je wilt doen '
                                . 'en vraag om bevestiging; het systeem laat de knop zien.',
                        ],
                        'Wacht op bevestiging.',
                    ),
                    'confirmation_required',
                    $started_at,
                );
            }

            $call = $call->withArguments($approval->arguments);
        }

        try {
            $result = $this->context->run($call->user, fn () => $tool->execute($call));
        } catch (Throwable $e) {
            Log::error('Tool mislukt', ['tool' => $call->name, 'exception' => $e]);

            return $this->record($call, ToolResult::failed($e->getMessage()), 'error', $started_at);
        }

        return $this->record($call, $result, $result->is_error ? 'error' : 'ok', $started_at);
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
