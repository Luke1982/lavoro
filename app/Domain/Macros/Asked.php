<?php

namespace App\Domain\Macros;

/**
 * A setting left to the moment the macro runs: stored as {"ask": "<the question>"}
 * instead of a value.
 */
final class Asked
{
    public static function is(mixed $value): bool
    {
        return is_array($value) && array_key_exists('ask', $value);
    }

    /** The setting's value: the answer when it was asked, the stored value otherwise. */
    public static function value(array $step, string $field, MacroRun $run): mixed
    {
        return self::is($step[$field] ?? null) ? $run->answer($step['id'], $field) : ($step[$field] ?? null);
    }

    /**
     * Which of a step's askable settings it asks, each with its question.
     *
     * @param  array<string, AnswerKind>  $askable
     * @return array<string, array{prompt: string, kind: AnswerKind}>
     */
    public static function in(array $step, array $askable): array
    {
        return collect($askable)
            ->filter(fn (AnswerKind $kind, string $field) => self::is($step[$field] ?? null))
            ->map(fn (AnswerKind $kind, string $field) => ['prompt' => $step[$field]['ask'], 'kind' => $kind])
            ->all();
    }

    /** How an asked setting reads in a step's sentence: `gevraagd ("Hoeveel airco's?")`. */
    public static function quoted(string $prompt): string
    {
        return 'gevraagd ("' . $prompt . '")';
    }
}
