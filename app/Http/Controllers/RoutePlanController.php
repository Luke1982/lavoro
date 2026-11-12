<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoutePlanRequest;
use App\Models\User;
use App\Services\RoutePlanner;
use Illuminate\Http\JsonResponse;

class RoutePlanController extends Controller
{
    public function store(RoutePlanRequest $request, RoutePlanner $planner): JsonResponse
    {
        abort_unless($planner->isAvailable(), 404);

        $home = $request->validated('start_user_id');

        return response()->json($planner->plan(
            $request->validated('stops'),
            $home ? User::findOrFail($home) : $request->validated('start'),
            $request->validated('workday'),
            $request->validated('order'),
        ));
    }
}
