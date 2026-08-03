<?php

namespace App\Http\Controllers;

use App\Http\Requests\GeocodeLookupRequest;
use App\Services\Geocoder;

class GeocodeController extends Controller
{
    public function lookup(GeocodeLookupRequest $request, Geocoder $geocoder)
    {
        $coords = $geocoder->lookup($request->validated('address'));

        if (!$coords) {
            return response()->json(['found' => false], 404);
        }

        return response()->json(['found' => true] + $coords);
    }
}
