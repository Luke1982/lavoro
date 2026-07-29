<?php

namespace App\Domain\Tools\Write\Concerns;

/**
 * Reading a value that has to be one of a fixed few.
 *
 * The schema lists them, but a schema is a suggestion here: arguments come out of
 * a language model and nothing enforces the enum on the way in. Anything that is
 * not on the list has to be refused rather than written — a status of "Wachtend"
 * would go into the column and then be a status no screen in the application
 * knows how to show.
 */
trait ChecksEnums
{
    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    private function oneOf(?string $value, string $enum, string $default): ?string
    {
        if (blank($value)) {
            return $default;
        }

        foreach ($enum::cases() as $case) {
            if (strcasecmp($case->value, $value) === 0) {
                return $case->value;
            }
        }

        return null;
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    private function valuesOf(string $enum): string
    {
        return implode(', ', array_map(fn ($case) => $case->value, $enum::cases()));
    }
}
