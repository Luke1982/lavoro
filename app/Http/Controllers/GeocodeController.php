<?php

namespace App\Http\Controllers;

use App\Http\Requests\GeocodeLookupRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeocodeController extends Controller
{
    public function lookup(GeocodeLookupRequest $request)
    {
        $address = Str::of($request->validated('address'))->squish()->lower()->value();
        $cache_key = 'geocode:' . sha1($address);

        $coords = Cache::rememberForever($cache_key, function () use ($address) {
            $response = Http::withHeaders([
                'User-Agent'      => config('app.name') . ' geocoder (' . config('app.url') . ')',
                'Accept-Language' => 'nl',
            ])->get('https://nominatim.openstreetmap.org/search', [
                'q'            => $address,
                'format'       => 'json',
                'limit'        => 1,
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
        });

        if (!$coords) {
            Cache::forget($cache_key);

            return response()->json(['found' => false], 404);
        }

        return response()->json(['found' => true] + $coords);
    }
}
