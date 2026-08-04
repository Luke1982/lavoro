<?php

namespace App\Domain\Assistant;

use App\Domain\Planning\Clock;
use App\Models\AssistantQuestion;
use App\Models\AssistantToolCall;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One conversation written out so somebody can find out what went wrong in it.
 *
 * A transcript copied off the screen is not enough. Every fault worth finding
 * this year was in what the tools were actually called with and what they handed
 * back — a place counted off twenty-five rows, a location looked up by a number
 * belonging to another customer, a crew returned without its ids. None of that
 * appears in the prose; it is the prose that looks fine.
 *
 * So this writes the arguments and the results beside the answer, in the order
 * they happened.
 */
class ConversationReport
{
    /** Enough of a result to see the shape of it, without pasting a database in. */
    private const RESULT_CHARS = 1200;

    public function markdownFor(string $conversation, User $user, ?string $reason = null): ?string
    {
        $turns = AssistantQuestion::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation)
            ->orderBy('id')
            ->get();

        if ($turns->isEmpty()) {
            return null;
        }

        $lines = [
            '# Gesprek ' . $conversation,
            '',
            '- **Gebruiker:** ' . $user->name . ' (#' . $user->id . ')',
            '- **Begonnen:** ' . Clock::toLocal($turns->first()->created_at)->toDateTimeString(),
            '- **Beurten:** ' . $turns->count(),
            '- **Kosten:** € ' . number_format($turns->sum('cost_micros') / 1_000_000, 4, ',', ''),
            '',
            '> Geschreven vanuit de applicatie. De argumenten en resultaten hieronder komen uit de '
                . 'auditregels, niet uit het antwoord — juist daar zitten de fouten die in de tekst goed lijken.',
            '',
        ];

        /**
         * First, because it is the reader's brief. The transcript says what
         * happened; only the melder can say what should have happened instead.
         */
        if (filled($reason)) {
            $lines[] = '## Waarom gemeld';
            $lines[] = '';
            $lines[] = trim($reason);
            $lines[] = '';
        }

        foreach ($this->factsFor($conversation, $user) as $line) {
            $lines[] = $line;
        }

        /** Consumed as the turns are walked, so repeats keep their own answers. */
        $results = $this->resultsAround($turns, $user);

        foreach ($turns as $index => $turn) {
            $lines[] = '## Beurt ' . ($index + 1) . ' — ' . Clock::toLocal($turn->created_at)->toTimeString();
            $lines[] = '';
            $lines[] = '**Gevraagd:** ' . ($turn->is_continuation ? '_(doorgegaan na bevestiging)_' : $turn->question);

            if (filled($turn->page)) {
                $lines[] = '';
                $lines[] = '**Vanaf pagina:** `' . $turn->page . '`';
            }

            $lines[] = '';

            foreach ($turn->tools ?? [] as $call) {
                /**
                 * The turn stores only the tool's name — a bare string. The first
                 * version of this expected the full call and rendered "Tool ?"
                 * with empty arguments for every real conversation, while its own
                 * tests passed on fixtures shaped like nothing the application
                 * ever writes. The arguments live in the audit rows, so that is
                 * where they are read from, paired with their own result.
                 */
                $named = is_array($call) ? (string) ($call['name'] ?? '?') : (string) $call;

                /** Taken in order and used once, so repeats keep their own answers. */
                $answered = empty($results[$named]) ? null : array_shift($results[$named]);

                $lines[] = '### Tool `' . $named . '`';
                $lines[] = '';
                $lines[] = '```json';
                $lines[] = json_encode($answered['arguments'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $lines[] = '```';

                if ($answered !== null) {
                    $lines[] = '';
                    $lines[] = 'Kwam terug (' . $answered['outcome'] . '):';
                    $lines[] = '';
                    $lines[] = '```';
                    $lines[] = mb_substr((string) $answered['result'], 0, self::RESULT_CHARS);
                    $lines[] = '```';
                }

                $lines[] = '';
            }

            $lines[] = '**Antwoord:**';
            $lines[] = '';
            $lines[] = filled($turn->failure)
                ? '_Mislukt: ' . $turn->failure . '_'
                : (string) $turn->answer;
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return array<int, string> */
    private function factsFor(string $conversation, User $user): array
    {
        $facts = app(ConversationFacts::class)->for($conversation, $user);

        if ($facts === []) {
            return [];
        }

        $lines = ['## Wat het gesprek had vastgelegd', ''];

        foreach ($facts as $noun => $fact) {
            $lines[] = '- **' . $noun . '** #' . $fact['id'] . (blank($fact['label']) ? '' : ' — ' . $fact['label']);
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * What the tools handed back, matched to this conversation by who ran them
     * and when.
     *
     * The audit table records no conversation of its own, so this is bounded by
     * the first and last question in the thread rather than joined to it. One
     * person holds one conversation at a time in the box, so that is sound in
     * practice — but it is a window, not a key, and the report says which tool
     * name a result belongs to rather than pretending to know the call.
     *
     * @param  Collection<int, AssistantQuestion>  $turns
     * @return array<string, array<int, array{arguments: mixed, outcome: string, result: ?string}>>
     */
    private function resultsAround(Collection $turns, User $user): array
    {
        return AssistantToolCall::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $turns->first()->created_at)
            ->where('created_at', '<=', $turns->last()->created_at->addMinutes(5))
            ->orderBy('id')
            ->get(['tool', 'arguments', 'outcome', 'result'])
            ->groupBy('tool')
            ->map(fn (Collection $calls) => $calls->map(fn (AssistantToolCall $call) => [
                'arguments' => $call->arguments,
                'outcome' => $call->outcome,
                'result' => $call->result,
            ])->all())
            ->all();
    }
}
