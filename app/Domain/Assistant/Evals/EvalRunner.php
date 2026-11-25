<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Assistant\AssistantSession;
use App\Domain\Assistant\ConversationFiles;
use App\Domain\Assistant\ConversationPhotos;
use App\Domain\Tools\ToolResult;
use App\Models\AssistantUsage;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * Plays one scenario through to the end the way the chat box would: asks the
 * opening question, and hands every answer to the simulated user, who clicks
 * one confirmation, picks an option, types, or stops. After a click that worked
 * the conversation carries on by itself; after one that failed it waits for the
 * user, as AssistantPanel.vue's confirmAction() does. Then it checks what was
 * made, grades it, and rolls the customer database back to where it was.
 *
 * The history sent to the model is built as the box builds it; the shared cases
 * in tests/fixtures/assistant-history.json keep the two from drifting apart.
 */
final class EvalRunner
{
    /**
     * @return array{scenario: string, suite: string, checks: array, passed: int, total: int, failed: bool, verdict: ?array, cost_micros: int, helper_cost_micros: int, transcript: EvalTranscript}
     */
    public function run(EvalScenario $scenario, bool $judge = true): array
    {
        $transcript = new EvalTranscript;
        $simulated = new SimulatedUser($scenario->persona());

        $checks = [...$this->playAndCheck($scenario, $transcript, $simulated), Check::endedCleanly($transcript)];
        /** A run that broke off before its first turn has nothing to read, and the judge would be paid to say so. */
        $judging = $judge && $transcript->turns !== [] ? new EvalJudge : null;
        $verdict = $judging ? $this->verdict($judging, $scenario, $transcript) : null;
        $passed = count(array_filter($checks, fn (Check $check) => $check->passed));

        return [
            'scenario' => $scenario->name(),
            'suite' => $scenario->suite(),
            'checks' => array_map(fn (Check $check) => $check->toArray(), $checks),
            'passed' => $passed,
            'total' => count($checks),
            'failed' => $passed < count($checks) || ($verdict['failed'] ?? false),
            'verdict' => $verdict,
            'cost_micros' => $transcript->assistantCostMicros(),
            'helper_cost_micros' => $simulated->costMicros() + ($judging?->costMicros() ?? 0),
            'transcript' => $transcript,
        ];
    }

    /**
     * One turn of the assistant, asked or carried on, with every tool call it
     * made and what the box would draw under it.
     *
     * The loop does not hand out what it spent when it throws, so a turn that
     * failed is priced from the meter's rows written since it began. Another
     * conversation of the same user at the same moment would be counted in.
     *
     * A turn that worked is priced from the answer, which leaves out the call
     * that sorted the question: that one is on the meter only. So the meter is
     * read on both paths, and a turn that worked counts whichever is more —
     * the meter, unless writing to it failed — so both count the same calls.
     *
     * @param  array<int, array{question: string, answer: string}>  $history
     */
    public function turn(string $kind, User $user, string $question, array $files = [], ?string $conversation = null, array $history = []): array
    {
        $session = app(AssistantSession::class);
        $tools = [];
        $watcher = $session->watcher($user, $conversation)->listen(
            function (string $name, array $arguments, bool $failed, ?ToolResult $result) use (&$tools) {
                $tools[] = ['name' => $name, 'arguments' => $arguments, 'failed' => $failed, 'content' => $result?->content];
            }
        );

        $metered_from = (int) AssistantUsage::query()->max('id');
        $turn = ['kind' => $kind, 'question' => $question, 'answer' => '', 'model' => '?', 'rounds' => 0, 'cost_micros' => 0, 'cost_from_meter' => false, 'error' => null];

        try {
            $answer = $kind === 'ask'
                ? $session->ask(
                    user: $user,
                    question: $question,
                    watcher: $watcher,
                    conversation: $conversation,
                    history: $history,
                    uploaded_files: array_values(array_filter($files, fn ($file) => !str_starts_with($file->media_type, 'image/'))),
                    uploaded_pictures: array_values(array_filter($files, fn ($file) => str_starts_with($file->media_type, 'image/'))),
                )
                : $session->proceed(user: $user, watcher: $watcher, conversation: $conversation, history: $history);

            $turn['answer'] = $answer->text;
            $turn['model'] = $answer->final->billableModel();
            $turn['rounds'] = $answer->tool_rounds;
            $turn['cost_micros'] = max($answer->cost_micros, self::meteredSince($metered_from, $user));
        } catch (Throwable $e) {
            $turn['error'] = $e->getMessage();
            $turn['cost_micros'] = self::meteredSince($metered_from, $user);
            $turn['cost_from_meter'] = true;
        }

        $drawn = $watcher->toArray();

        return [...$turn, 'tools' => $tools, 'pending' => $drawn['pending'], 'choices' => $drawn['choices']];
    }

    private static function meteredSince(int $id, User $user): int
    {
        return (int) AssistantUsage::query()->where('id', '>', $id)->where('user_id', $user->id)->sum('cost_micros');
    }

    /**
     * The history the box sends the model, built the way the box builds it.
     *
     * @param  array<int, array{question: string, answer: string, done: array<int, array{text: string, failed: bool}>}>  $exchanges
     * @return array<int, array{question: string, answer: string}>
     */
    public static function historyFor(array $exchanges): array
    {
        $kept = array_values(array_filter($exchanges, fn ($exchange) => $exchange['answer'] !== '' || $exchange['done'] !== []));

        return array_map(fn ($exchange) => [
            'question' => $exchange['question'] !== '' ? $exchange['question'] : AssistantSession::CARRIED_ON,
            'answer' => mb_substr(implode("\n\n", [
                $exchange['answer'],
                ...array_map(fn ($done) => ($done['failed'] ? '[niet gelukt] ' : '[al uitgevoerd] ') . $done['text'], $exchange['done']),
            ]), 0, AssistantSession::REMEMBERED_ANSWER_CHARS),
        ], array_slice($kept, -AssistantSession::REMEMBERED_EXCHANGES));
    }

    /** The conversation and the scenario's checks, inside the transaction that is rolled back. */
    private function playAndCheck(EvalScenario $scenario, EvalTranscript $transcript, SimulatedUser $simulated): array
    {
        $conversation = (string) Str::uuid();

        try {
            return EvalSandbox::rolledBack(function () use ($scenario, $transcript, $simulated, $conversation) {
                $scenario->prepare();
                $this->play($scenario, $conversation, $transcript, $simulated);

                return $scenario->checks($transcript);
            });
        } catch (Throwable $e) {
            $transcript->end(EvalEnding::error, 'runner: ' . $e->getMessage());

            return [];
        } finally {
            app(ConversationFiles::class)->discard($conversation);
            app(ConversationPhotos::class)->discard($conversation);
        }
    }

    private function play(EvalScenario $scenario, string $conversation, EvalTranscript $transcript, SimulatedUser $simulated): void
    {
        $user = $scenario->user();
        $exchanges = [];
        $moves = 0;
        $next = ['kind' => 'ask', 'question' => $scenario->opening(), 'files' => $scenario->attachments()];

        while ($next !== null) {
            $turn = $this->turn($next['kind'], $user, $next['question'], $next['files'], $conversation, self::historyFor($exchanges));
            $transcript->turns[] = $turn;
            $exchanges[] = ['question' => $next['kind'] === 'ask' ? $next['question'] : '', 'answer' => $turn['answer'], 'done' => []];

            if ($turn['error'] !== null) {
                $transcript->end(EvalEnding::error, $turn['error']);

                return;
            }

            $next = $this->respond($user, $transcript, $simulated, $exchanges, $moves, $scenario->maxMoves());
        }
    }

    /**
     * What the simulated user does with the latest answer, until it asks for a
     * next turn or the run ends. A click that worked carries on; one that did
     * not is shown to the user, who decides again.
     *
     * @return array{kind: string, question: string, files: array}|null
     */
    private function respond(User $user, EvalTranscript $transcript, SimulatedUser $simulated, array &$exchanges, int &$moves, int $max_moves): ?array
    {
        $index = array_key_last($transcript->turns);
        $turn = $transcript->turns[$index];
        $buttons = $turn['pending'];

        while (true) {
            $move = $simulated->next(
                $transcript->dialogue($index),
                $transcript->saidFor($index),
                $turn['answer'],
                $buttons,
                $turn['choices'],
                $transcript->clicksOn($index),
            );

            if ($move['action'] === 'stop') {
                $transcript->move($index, 'gestopt', $move['reason']);
                $transcript->end(EvalEnding::user_stopped, $move['reason']);

                return null;
            }

            if ($move['action'] === 'invalid') {
                $transcript->end(EvalEnding::invalid_move, $move['reason']);

                return null;
            }

            if (++$moves > $max_moves) {
                $transcript->end(EvalEnding::cut_off, 'na ' . $max_moves . ' zetten van de gebruiker');

                return null;
            }

            if ($move['action'] === 'choose') {
                return $this->ask($transcript, $index, 'gekozen', self::picked($turn['choices'], $move['options']));
            }

            if ($move['action'] === 'reply') {
                return $this->ask($transcript, $index, 'getypt', $move['text']);
            }

            $button = $buttons[$move['index']];
            $buttons = array_values(array_diff_key($buttons, [$move['index'] => true]));
            $done = $this->confirm($user, $button, $transcript, $index);
            $exchanges[array_key_last($exchanges)]['done'][] = $done;

            if (!$done['failed']) {
                return ['kind' => 'proceed', 'question' => AssistantSession::CARRIED_ON, 'files' => []];
            }
        }
    }

    private function ask(EvalTranscript $transcript, int $turn, string $kind, string $question): array
    {
        $transcript->move($turn, $kind, $question);

        return ['kind' => 'ask', 'question' => $question, 'files' => []];
    }

    /**
     * What clicking the options sends, one per choice question, built as the
     * box's choiceAnswer() builds it; tests/fixtures/assistant-history.json
     * holds the two to the same words.
     *
     * @param  array<int, array{options: array<int, array{label: string, reference?: ?string}>}>  $choices
     * @param  array<int, int>  $options  Per choice, the position of the option picked.
     */
    public static function picked(array $choices, array $options): string
    {
        $labels = [];

        foreach (array_values($choices) as $index => $choice) {
            $option = $choice['options'][$options[$index]];
            $labels[] = $option['label'] . ' (' . ($option['reference'] ?? '') . ')';
        }

        return 'Ik bedoel: ' . implode(' en ', $labels);
    }

    /**
     * One click, answered the way the controller answers it.
     *
     * @return array{text: string, failed: bool}
     */
    private function confirm(User $user, array $button, EvalTranscript $transcript, int $turn): array
    {
        $confirmed = app(AssistantSession::class)->confirm($user, $button['token']);

        $transcript->click($turn, $button['preview'] ?? $button['tool'], [
            'tool' => $confirmed->approval?->tool ?? $button['tool'],
            'arguments' => $confirmed->approval?->arguments ?? $button['arguments'],
            'ok' => $confirmed->succeeded(),
            'summary' => $confirmed->message,
            'content' => $confirmed->result?->content,
        ]);

        return ['text' => $confirmed->message, 'failed' => !$confirmed->succeeded()];
    }

    /** @return array{criteria: array, worst_problem: string, failed: bool} */
    private function verdict(EvalJudge $judge, EvalScenario $scenario, EvalTranscript $transcript): array
    {
        try {
            return $judge->grade($scenario, $transcript) + ['failed' => false];
        } catch (Throwable $e) {
            return ['criteria' => [], 'worst_problem' => 'beoordelen mislukt: ' . $e->getMessage(), 'failed' => true];
        }
    }
}
