<?php

namespace App\Enums\Traits;

trait EnumComboArrayTrait
{
    /**
     * @return array<int, array{id: string, name: string}>
     */
    public static function comboArray(): array
    {
        return array_map(fn($case) => [
            'id' => $case->name,
            'name' => $case->value,
        ], self::cases());
    }
}
