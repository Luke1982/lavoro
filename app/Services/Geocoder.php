<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Adres naar coördinaten, via Nominatim.
 *
 * Een gevonden adres wordt voor altijd bewaard: adressen verhuizen niet, en
 * Nominatim is een gratis dienst met één verzoek per seconde als plafond.
 *
 * Een níét gevonden adres wordt ook bewaard, maar een week. Zonder dat blijft
 * iedere ronde dezelfde onvindbare adressen opnieuw opvragen — postbussen,
 * halve straatnamen, adressen over de grens — en komt hij nooit toe aan de
 * adressen die wél te vinden zijn. Een week later mag het opnieuw, want een
 * verkeerd getypt adres kan intussen verbeterd zijn.
 *
 * lookup() staat los van cached() omdat alles wat tijdens het tekenen van een
 * pagina draait alleen de cache mag lezen: wachten op een dienst die we niet in
 * de hand hebben, hoort niet in een paginaverzoek thuis.
 */
class Geocoder
{
    /** Hoe lang een vergeefse poging blijft staan voor hij opnieuw gedaan mag worden. */
    public const MISS_TTL_SECONDS = 604800;

    public static function cacheKey(string $address): string
    {
        return 'geocode:' . sha1(self::normalise($address));
    }

    /**
     * De coördinaten die al bekend zijn, zonder ooit een verzoek te doen. Een
     * eerdere misser telt hier niet als antwoord — die is geen plek.
     *
     * @return array{lat: float, lon: float}|null
     */
    public function cached(string $address): ?array
    {
        $remembered = Cache::get(self::cacheKey($address));

        return is_array($remembered) ? $remembered : null;
    }

    /** Of dit adres kortgeleden al eens vergeefs is opgezocht. */
    public function recentlyMissed(string $address): bool
    {
        return Cache::get(self::cacheKey($address)) === false;
    }

    /**
     * Zoekt het adres op en onthoudt de uitkomst, gevonden of niet. Alleen
     * aanroepen buiten een paginaverzoek om — vanuit een commando of de wachtrij.
     *
     * @return array{lat: float, lon: float}|null
     */
    public function lookup(string $address): ?array
    {
        $key = self::cacheKey($address);
        $remembered = Cache::get($key);

        if (is_array($remembered)) {
            return $remembered;
        }

        if ($remembered === false) {
            return null;
        }

        $coordinates = $this->ask(self::normalise($address));

        if ($coordinates) {
            Cache::forever($key, $coordinates);

            return $coordinates;
        }

        Cache::put($key, false, self::MISS_TTL_SECONDS);

        return null;
    }

    /** @return array{lat: float, lon: float}|null */
    private function ask(string $address): ?array
    {
        $response = Http::withHeaders([
            'User-Agent' => config('app.name') . ' geocoder (' . config('app.url') . ')',
            'Accept-Language' => 'nl',
        ])->get('https://nominatim.openstreetmap.org/search', [
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'nl',
        ]);

        $result = $response->ok() ? ($response->json()[0] ?? null) : null;

        if (!$result || !isset($result['lat'], $result['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $result['lat'],
            'lon' => (float) $result['lon'],
        ];
    }

    private static function normalise(string $address): string
    {
        return Str::of($address)->squish()->lower()->value();
    }
}
