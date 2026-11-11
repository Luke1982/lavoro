<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Planning\DayRouteAssigned;
use App\Domain\Signals\Planning\DayRouteRemoved;
use App\Domain\Signals\Signals;
use App\Http\Requests\DayRouteDestroyRequest;
use App\Http\Requests\DayRouteIndexRequest;
use App\Http\Requests\DayRouteStoreRequest;
use App\Models\DayRoute;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Routes from the map in people's days: what the planner shows at the top of a day. */
class DayRouteController extends Controller
{
    public function index(DayRouteIndexRequest $request): JsonResponse
    {
        $viewer = $request->user();

        $routes = DayRoute::query()
            ->visibleTo($viewer)
            ->whereBetween('date', [$request->validated('start'), $request->validated('end')])
            ->with('user')
            ->get();

        return response()->json($routes->map(fn (DayRoute $route) => self::present($route, $viewer)));
    }

    /** Another route in a day that already has one takes its place. */
    public function store(DayRouteStoreRequest $request): JsonResponse
    {
        $mechanic = $request->mechanic();

        $route = DayRoute::query()->firstOrNew(['user_id' => $mechanic->id, 'date' => $request->validated('date')]);
        $replaced_summary = $route->exists ? $route->summary() : null;

        DB::transaction(function () use ($request, $route, $mechanic, $replaced_summary) {
            $route->fill($request->safe()->only(['stops', 'finish', 'links']))->save();
            Signals::dispatch(new DayRouteAssigned($route, $mechanic, $replaced_summary));
        });

        return response()->json(self::present($route->setRelation('user', $mechanic), $request->user())
            + ['replaced' => $replaced_summary !== null]);
    }

    public function destroy(DayRouteDestroyRequest $request, DayRoute $day_route): Response
    {
        DB::transaction(function () use ($day_route) {
            $day_route->delete();
            Signals::dispatch(new DayRouteRemoved($day_route, $day_route->user));
        });

        return response()->noContent();
    }

    private static function present(DayRoute $route, User $viewer): array
    {
        return [
            'id' => $route->id,
            'user_id' => $route->user_id,
            'user_name' => $route->user?->name,
            'date' => $route->date->toDateString(),
            'day_label' => $route->dayLabel(),
            'stops' => $route->stops,
            'finish' => $route->finish,
            'links' => $route->links,
            'summary' => $route->summary(),
            'can_remove' => $viewer->can('delete', $route),
        ];
    }
}
