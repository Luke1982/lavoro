<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Adres naar coördinaten, via Nominatim.
 *
 * Antwoorden worden voor altijd bewaard: een adres verhuist niet, en Nominatim
 * is een gratis dienst met een verzoek per seconde als plafond. Daarom staat
 * lookup() ook los van cached() — alles wat tijdens het tekenen van een pagina
 * draait mag alleen de cache lezen en nooit het net op, anders staat de pagina
 * stil op een dienst die we niet in de hand hebben.
 */
class Geocoder
{
    public static function cacheKey(string $address): string
    {
        return 'geocode:' . sha1(self::normalise($address));
    }

    /**
     * Wat er al opgezocht is, zonder ooit een verzoek te doen.
     *
     * @return array{lat: float, lon: float}|null
     */
    public function cached(string $address): ?array
    {
        return Cache::get(self::cacheKey($address));
    }

    /**
     * Zoekt het adres op en onthoudt het antwoord. Alleen aanroepen buiten een
     * paginaverzoek om — vanuit een commando of een wachtrij.
     *
     * @return array{lat: float, lon: float}|null
     */
    public function lookup(string $address): ?array
    {
        $key = self::cacheKey($address);

        $coordinates = Cache::rememberForever($key, fn () => $this->ask(self::normalise($address)));

        if (!$coordinates) {
            Cache::forget($key);
        }

        return $coordinates;
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
