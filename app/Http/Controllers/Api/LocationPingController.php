<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreLocationPingsRequest;
use App\Models\LocationPing;
use Carbon\Carbon;

class LocationPingController extends Controller
{
    public function store(StoreLocationPingsRequest $request): \Illuminate\Http\JsonResponse
    {
        $user_id = $request->user()->id;
        $now = now()->toDateTimeString();

        $rows = array_map(fn($ping) => array_merge($ping, [
            'user_id'     => $user_id,
            'recorded_at' => Carbon::parse($ping['recorded_at'])->utc()->toDateTimeString(),
            'created_at'  => $now,
        ]), $request->validated('pings'));

        LocationPing::insert($rows);

        return response()->json(['stored' => count($rows)]);
    }
}
