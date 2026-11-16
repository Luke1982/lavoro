<?php

namespace App\Support;

use App\Models\GeneralSetting;

/**
 * Whether the upcoming inspections and their map leave out machines under a running
 * maintenance contract for everyone. A contract generates their werkbonnen itself; as long
 * as the company does not hide them, each person who may see contracts chooses.
 */
class UpcomingContractAssets
{
    public const SETTING = 'upcoming_hide_contract_assets';

    public static function hiddenForEveryone(): bool
    {
        return GeneralSetting::get(self::SETTING, '0') === '1';
    }

    public static function hideForEveryone(bool $hide): void
    {
        GeneralSetting::set(self::SETTING, $hide ? '1' : '0');
    }
}
