<?php

namespace App\Support;

use App\Models\GeneralSetting;

/**
 * The pin colours on the map of upcoming inspections: one for an address with
 * an expired machine, one per group of days, and one for everything after the
 * last group. Until an admin saves their own, the map keeps these defaults.
 * Every pin carries a white number, so a colour needs contrast with white.
 */
class MapDueColors
{
    public const SETTING = 'map_due_colors';

    public static function defaults(): array
    {
        return [
            'expired' => '#dc2626',
            'bands' => [
                ['days' => 30, 'color' => '#ea580c'],
                ['days' => 60, 'color' => '#ca8a04'],
            ],
            'later' => '#16a34a',
        ];
    }

    public static function get(): array
    {
        $stored = json_decode((string) GeneralSetting::get(self::SETTING), true);

        return is_array($stored) ? $stored : self::defaults();
    }

    /** The map gives an address the first group it fits in, so groups are kept in order of days. */
    public static function save(array $colors): void
    {
        $colors['bands'] = collect($colors['bands'])
            ->map(fn (array $band) => ['days' => (int) $band['days'], 'color' => $band['color']])
            ->sortBy('days')
            ->values()
            ->all();

        GeneralSetting::set(self::SETTING, json_encode($colors));
    }
}
