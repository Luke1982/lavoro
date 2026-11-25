<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnavailabilityIndexRequest;
use App\Models\UserUnavailability;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class UnavailabilityApiController extends Controller
{
    public function index(UnavailabilityIndexRequest $request): JsonResponse
    {
        $start = Carbon::parse($request->query('start'))->startOfDay();
        $end = Carbon::parse($request->query('end'))->endOfDay();

        $blocks = UserUnavailability::forWeek($start, $end)
            ->get()
            ->flatMap(fn ($entry) => $entry->expandForWeek($start, $end))
            ->values();

        return response()->json($blocks);
    }
}
