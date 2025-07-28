<?php

namespace App\Enums\Traits;

trait EnumComboBoxArrayTrait
{
    /**
     * Returns an array designed to be used with the
     * Vue ComboBox component.
     *
     * @return array<string, string>
     */
    public static function comboBoxArray(): array
    {
        return array_map(
            fn($case) => ['id' => $case->name, 'name' => $case->value],
            self::cases()
        );
    }
}
