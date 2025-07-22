<?php

namespace App\Enums\Traits;

trait EnumAssocArrayTrait
{
    /**
     * Returns an associative array of enum cases.
     *
     * @return array<string, string>
     */
    public static function assocArray(): array
    {
        $arr = [];
        foreach (self::cases() as $case) {
            $arr[$case->name] = $case->value;
        }
        return $arr;
    }
}
