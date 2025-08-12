<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventStoreRequest;
use App\Models\Event;
use Illuminate\Http\Request;

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
        })->with('eventType')
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

        return response()->json($event);
    }
}
