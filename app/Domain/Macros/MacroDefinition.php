<?php

namespace App\Domain\Macros;

use App\Models\Location;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

            foreach (Validator::make($step, $this->rulesFor($block, $step))->errors()->messages() as $field => $messages) {
                $errors[$at . '.' . $field] = $messages[0];
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
                . self::spoken($answers[$question['step_id']][$question['field']] ?? null, $question['kind']))
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
     * @return array<int, array{step_id: string, field: string, question: string, kind: string, only_if?: string}>
     */
    public function questions(): array
    {
        return $this->questionsIn($this->steps, null);
    }

    /**
     * What a step asks when the macro runs: field => [prompt, kind].
     *
     * @return array<string, array{prompt: string, kind: string}>
     */
    public static function asks(array $step): array
    {
        $block = MacroSteps::find($step['type'] ?? null);

        return collect($block?->askable() ?? [])
            ->filter(fn (string $kind, string $field) => $kind === 'file' || $kind === 'yes_no' || Asked::is($step[$field] ?? null))
            ->map(fn (string $kind, string $field) => [
                'prompt' => match ($kind) {
                    'file' => $step['prompt'] ?? 'Bestand',
                    'yes_no' => $step['question'] ?? '',
                    default => $step[$field]['ask'],
                },
                'kind' => $kind,
            ])
            ->all();
    }

    /** @return array<string, array<int, mixed>> */
    private function rulesFor(MacroStep $block, array $step): array
    {
        $rules = $block->rules();

        foreach ($block->askable() as $field => $kind) {
            if (Asked::is($step[$field] ?? null)) {
                $rules = array_diff_key($rules, [$field => true]) + [$field . '.ask' => ['required', 'string', 'max:255']];
            }
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

    private static function spoken(mixed $answer, string $kind): string
    {
        return match (true) {
            $answer instanceof UploadedFile => $answer->getClientOriginalName(),
            $kind === 'yes_no' => filter_var($answer, FILTER_VALIDATE_BOOLEAN) ? 'ja' : 'nee',
            $kind === 'location' && $answer === 'all' => 'alle machines',
            $kind === 'location' => Location::find($answer)->title ?? 'locatie #' . $answer,
            default => (string) $answer,
        };
    }

    /** @return array<int, string> */
    private function sentences(array $steps, string $number_prefix, string $indent): array
    {
        $lines = [];

        foreach (array_values($steps) as $index => $step) {
            $number = $number_prefix . ($index + 1) . '.';
            $lines[] = $indent . $number . ' ' . MacroSteps::find($step['type'])->describe($step);

            if ($step['type'] === 'question') {
                foreach (['yes' => 'Als ja:', 'no' => 'Als nee:'] as $lane => $heading) {
                    $lines[] = $indent . '   ' . $heading;
                    $lines = [...$lines, ...$this->sentences($step[$lane] ?? [], $number, $indent . '   ')];
                }
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

            foreach (['yes', 'no'] as $lane) {
                if ($step['type'] === 'question') {
                    $step[$lane] = $this->withAsks($step[$lane] ?? []);
                }
            }

            return $step;
        }, $steps);
    }
}
