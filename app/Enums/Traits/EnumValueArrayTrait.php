<?php

namespace App\Enums\Traits;

trait EnumValueArrayTrait
{
    /**
     * Returns an array of enum case values.
     *
     * @return array<string, string>
     */
    public static function valueArray(): array
    {
        $arr = [];
        foreach (self::cases() as $case) {
            $arr[] = $case->value;
        }
        return $arr;
    }
}
