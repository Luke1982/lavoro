<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\EventStoreRequest;

class EventApiController extends Controller
{
    public function index(Request $request)
    {
        $events = Event::where(function ($query) use ($request) {
            $query->where(function ($q) use ($request) {
                $q->where('start', '>=', $request->start)
                    ->where('end', '<=', $request->end);
            })->orWhere(function ($q) use ($request) {
                $q->where('start', '<', $request->start)
                    ->where('end', '>=', $request->start);
            })->orWhere(function ($q) use ($request) {
                $q->where('start', '<=', $request->end)
                    ->where('end', '>', $request->end);
            })->orWhere(function ($q) use ($request) {
                $q->where('start', '<', $request->start)
                    ->where('end', '>', $request->end);
            });
        })->with(['eventType', 'serviceOrders'])
            ->orderBy('start')
            ->get();

        return response()->json($events);
    }

    public function store(EventStoreRequest $request)
    {
        $event = Event::create($request->validated());

        $class = $request->eventable_type;
        $model = $class::findOrFail($request->eventable_id);

        $model->events()->attach($event->id);

        return response()->json($event->fresh(), 201);
    }

    public function update(Request $request, Event $event)
    {
        $event->update($request->all());

        if ($request->has('eventable_type') && $request->has('eventable_id')) {
            $class = $request->eventable_type;
            $model = $class::findOrFail($request->eventable_id);

            DB::table('eventables')
                ->where('event_id', $event->id)
                ->where('eventable_type', [
                    substr($request->eventable_type, 1),
                ])
                ->delete();

            $model->events()->attach($event->id);
        }

        return response()->json($event);
    }
}
