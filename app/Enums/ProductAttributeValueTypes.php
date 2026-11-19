<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboArrayTrait;

enum ProductAttributeValueTypes: string
{
    use EnumComboArrayTrait;

    case text = 'Tekst';
    case number = 'Getal';

    /** The Dutch name of the type stored as $name, or $name itself when it is none. */
    public static function labelOf(string $name): string
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case->value;
            }
        }

        return $name;
    }
}
