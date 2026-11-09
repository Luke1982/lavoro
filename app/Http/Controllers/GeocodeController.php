<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddressLookupRequest;
use App\Http\Requests\GeocodeLookupRequest;
use App\Services\AddressFinder;
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

    /** A suggestion from ComboSearchController::addresses, as street, postcode, place and coordinates. */
    public function address(AddressLookupRequest $request, AddressFinder $finder)
    {
        $address = $finder->find($request->validated('id'));

        if (!$address) {
            return response()->json(['message' => 'Dat adres is niet meer te vinden. Zoek het opnieuw.'], 404);
        }

        return response()->json($address);
    }
}
