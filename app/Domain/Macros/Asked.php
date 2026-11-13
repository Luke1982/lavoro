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
        $value = $step[$field] ?? null;

        return self::is($value) ? $run->answer($step['id'], $field) : $value;
    }

    /** '"Hoeveel airco\'s?"' for an asked setting, for the sentence a step reads as. */
    public static function quoted(array $value): string
    {
        return 'gevraagd ("' . $value['ask'] . '")';
    }
}
