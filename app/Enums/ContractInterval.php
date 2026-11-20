<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum ContractInterval: string
{
    use EnumComboBoxArrayTrait;

    case maandelijks = 'Maandelijks';
    case halfjaarlijks = 'Halfjaarlijks';
    case jaarlijks = 'Jaarlijks';
    case aangepast = 'Aangepast (dagen)';

    /** How often something with this interval happens in a year; nothing set counts as once. */
    public static function timesPerYear(?string $interval, ?int $days): float
    {
        return match ($interval) {
            self::maandelijks->value => 12.0,
            self::halfjaarlijks->value => 2.0,
            self::aangepast->value => $days ? round(365 / $days, 4) : 1.0,
            default => 1.0,
        };
    }
}
