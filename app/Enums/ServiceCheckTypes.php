<?php

namespace App\Enums;

use App\Enums\Traits\EnumAssocArrayTrait;

enum ServiceCheckTypes: string
{
    use EnumAssocArrayTrait;

    case radio      = 'Een keuze uit meerdere opties';
    case checkgroup = 'Meerdere keuzes uit meerdere opties';
    case boolean    = 'Ja of Nee';
    case number     = 'Een getal';
    case text       = 'Een tekst';

    public static function getTypesWithOptions(): array
    {
        return [
            self::radio->name => self::radio->value,
            self::checkgroup->name => self::checkgroup->value,
        ];
    }
}
