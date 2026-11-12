<?php

namespace App\Enums;

enum ServiceOrderTypes: string
{
    case installation = 'installation';
    case service = 'service';
    case mixed = 'mixed';

    /** What the werkbon screen calls it. */
    public function label(): string
    {
        return match ($this) {
            self::installation => 'installatie',
            self::service => 'service',
            self::mixed => 'gemengd',
        };
    }
}
