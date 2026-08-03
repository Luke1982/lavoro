<?php

namespace App\Jobs;

use App\Services\GeocodeBackfill;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Zoekt op de achtergrond de coördinaten op die de kaart mist.
 *
 * Het dashboard vraagt hierom zodra het adressen tegenkomt die het niet kan
 * plaatsen. Het opzoeken zelf mag niet in een paginaverzoek: Nominatim staat
 * één vraag per seconde toe, en daar hoort niemand op te wachten. Zo vult de
 * kaart zich vanzelf, in plaats van pas als iemand aan een commando denkt.
 *
 * Het budget is klein met opzet. Eén baan houdt een worker dus hooguit een
 * halve minuut bezig, en wat er overblijft komt bij het volgende bezoek weer
 * langs.
 */
class GeocodeMissingCoordinatesJob implements ShouldQueue
{
    use Queueable;

    /** Hoe lang na een ronde er geen nieuwe aangevraagd wordt. */
    public const COOLDOWN_SECONDS = 900;

    private const CACHE_KEY = 'geocode:sweep-requested';

    public function __construct(private int $budget = 25, private int $days = 60) {}

    /**
     * Vraagt om een ronde, maar hoogstens één per kwartier.
     *
     * Het dashboard wordt de hele dag door ververst; zonder deze rem zou elke
     * verversing er een baan bij zetten voor werk dat al in de wachtrij staat.
     */
    public static function request(int $budget = 25, int $days = 60): bool
    {
        if (!Cache::add(self::CACHE_KEY, true, self::COOLDOWN_SECONDS)) {
            return false;
        }

        self::dispatch($budget, $days);

        return true;
    }

    public function handle(GeocodeBackfill $backfill): void
    {
        $result = $backfill->run(budget: $this->budget, days: $this->days);

        Log::info('Geocode-ronde afgerond', $result);
    }
}
