<?php

namespace App\Domain\Macros;

use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * The steps of a macro as a tree: a list of steps, where a yes/no question holds
 * a list for each answer and the macro carries on below it. Every step has an id
 * of its own, which its answers are given under.
 */
final class MacroDefinition
{
    /** @param  array<int, array<string, mixed>>  $steps */
    public function __construct(private readonly array $steps) {}

    /**
     * What is wrong with the steps, by where it sits ("steps.2.yes.0.quantity").
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        $errors = [];
        $ids = [];

        $this->walk($this->steps, 'steps', function (mixed $step, string $at) use (&$errors, &$ids) {
            if (!is_array($step)) {
                $errors[$at] = 'Dit is geen stap.';

                return;
            }

            $block = is_string($step['type'] ?? null) ? MacroSteps::find($step['type']) : null;

            if (!$block) {
                $errors[$at . '.type'] = 'Onbekende stap.';

                return;
            }

            $id = $step['id'] ?? null;

            if (!is_string($id) || preg_match('/^[\w-]+$/', $id) !== 1 || in_array($id, $ids, true)) {
                $errors[$at . '.id'] = 'Elke stap heeft een eigen id nodig, van letters, cijfers, - en _.';
            }

            $ids[] = $id;

            $messages = Validator::make($step, $this->rulesFor($block, $step))->errors()->messages();

            foreach ($messages as $field => $field_messages) {
                $errors[$at . '.' . $field] = $field_messages[0];
            }
        });

        return $errors;
    }

    /**
     * The steps that run, in order, for these answers: at a question, its own step
     * and then the lane its answer chooses. An unanswered question ends the path.
     *
     * @param  array<string, array<string, mixed>>  $answers
     * @return array<int, array<string, mixed>>
     */
    public function path(array $answers): array
    {
        $path = [];
        $this->follow($this->steps, $answers, $path);

        return $path;
    }

    /** Whether this person may do every step of the macro, in every lane. */
    public function allows(User $user): bool
    {
        $allowed = true;

        $this->walk($this->steps, 'steps', function (array $step) use ($user, &$allowed) {
            $allowed = $allowed && MacroSteps::find($step['type'])->allows($user);
        });

        return $allowed;
    }

    /**
     * What these answers say on the path they choose, in words: "Hoeveel airco's? → 4".
     *
     * @param  array<string, array<string, mixed>>  $answers
     * @return array<int, string>
     */
    public function answered(array $answers): array
    {
        $on_path = array_column($this->path($answers), 'id');

        return collect($this->questions())
            ->filter(fn (array $question) => in_array($question['step_id'], $on_path, true))
            ->map(fn (array $question) => $question['question'] . ' → '
                . $question['kind']->spoken($answers[$question['step_id']][$question['field']] ?? null))
            ->values()
            ->all();
    }

    /**
     * The macro as numbered sentences, lanes indented under their question.
     *
     * @return array<int, string>
     */
    public function readable(): array
    {
        return $this->sentences($this->steps, '', '');
    }

    /**
     * The steps with what each asks when the macro runs, for the run window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forRunning(): array
    {
        return $this->withAsks($this->steps);
    }

    /**
     * Everything the macro asks, in order, in one flat list; what is asked in a
     * lane says which answer to its question leads there.
     *
     * @return array<int, array{step_id: string, field: string, question: string, kind: AnswerKind, only_if?: string}>
     */
    public function questions(): array
    {
        return $this->questionsIn($this->steps, null);
    }

    /**
     * What a step asks when the macro runs, by the field its answer is given under.
     *
     * @return array<string, array{prompt: string, kind: AnswerKind}>
     */
    public static function asks(array $step): array
    {
        return MacroSteps::find($step['type'])->asks($step);
    }

    /**
     * The block's rules, with an asked setting checked for its question instead.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(MacroStep $block, array $step): array
    {
        $rules = $block->rules();

        foreach (array_keys(Asked::in($step, $block->askable())) as $field) {
            unset($rules[$field]);
            $rules[$field . '.ask'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    private function walk(array $steps, string $at, callable $visit): void
    {
        foreach (array_values($steps) as $index => $step) {
            $visit($step, $at . '.' . $index);

            if (!is_array($step) || ($step['type'] ?? null) !== 'question') {
                continue;
            }

            foreach (['yes', 'no'] as $lane) {
                if (is_array($step[$lane] ?? null) && array_is_list($step[$lane])) {
                    $this->walk($step[$lane], $at . '.' . $index . '.' . $lane, $visit);
                }
            }
        }
    }

    /**
     * Adds the steps that run to the path, in order: at a question its own step and
     * then the lane its answer chooses. False once an unanswered question has ended
     * the macro, wherever it sits.
     */
    private function follow(array $steps, array $answers, array &$path): bool
    {
        foreach ($steps as $step) {
            $path[] = $step;

            if ($step['type'] !== 'question') {
                continue;
            }

            $answer = $answers[$step['id']]['answer'] ?? null;

            if ($answer === null || $answer === '') {
                return false;
            }

            $lane = filter_var($answer, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';

            if (!$this->follow($step[$lane] ?? [], $answers, $path)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    private function sentences(array $steps, string $number_prefix, string $indent): array
    {
        $lines = [];

        foreach (array_values($steps) as $index => $step) {
            $number = $number_prefix . ($index + 1) . '.';
            $lines[] = $indent . $number . ' ' . MacroSteps::find($step['type'])->describe($step);

            if ($step['type'] !== 'question') {
                continue;
            }

            foreach (['yes' => 'Als ja:', 'no' => 'Als nee:'] as $lane => $heading) {
                $under = $this->sentences($step[$lane] ?? [], $number, $indent . '   ');
                $lines = [...$lines, $indent . '   ' . $heading . ($under === [] ? ' niets.' : ''), ...$under];
            }
        }

        return $lines;
    }

    private function questionsIn(array $steps, ?string $only_if): array
    {
        $questions = [];

        foreach ($steps as $step) {
            foreach (self::asks($step) as $field => $ask) {
                $questions[] = [
                    'step_id' => $step['id'],
                    'field' => $field,
                    'question' => $ask['prompt'],
                    'kind' => $ask['kind'],
                ] + ($only_if === null ? [] : ['only_if' => $only_if]);
            }

            if ($step['type'] !== 'question') {
                continue;
            }

            foreach (['yes' => 'ja', 'no' => 'nee'] as $lane => $answer) {
                $condition = '"' . $step['question'] . '" = ' . $answer;
                $nested = $only_if === null ? $condition : $only_if . ' en ' . $condition;
                $questions = [...$questions, ...$this->questionsIn($step[$lane] ?? [], $nested)];
            }
        }

        return $questions;
    }

    private function withAsks(array $steps): array
    {
        return array_map(function (array $step) {
            $step['asks'] = self::asks($step);

            if ($step['type'] === 'question') {
                $step['yes'] = $this->withAsks($step['yes'] ?? []);
                $step['no'] = $this->withAsks($step['no'] ?? []);
            }

            return $step;
        }, $steps);
    }
}
