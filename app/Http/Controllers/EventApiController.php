<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\EventStoreRequest;
use Illuminate\Support\Facades\Auth;

class EventApiController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $user_id = $user?->id;
        $has_all = $user?->hasPermission('event.see_all');

        $base = Event::query();

        // Time range overlap conditions
        if ($request->start && $request->end) {
            $start = $request->start;
            $end = $request->end;
            $base->where(function ($q) use ($start, $end) {
                $q->whereBetween('start', [$start, $end])
                    ->orWhereBetween('end', [$start, $end])
                    ->orWhere(function ($qq) use ($start, $end) {
                        $qq->where('start', '<', $start)->where('end', '>', $end);
                    });
            });
        }

        if (!$has_all && $user_id) {
            $base->where(function ($q) use ($user_id) {
                $q->whereHas('executingUsers', fn($sq) => $sq->where('users.id', $user_id))
                  ->orWhereHas('owners', fn($sq) => $sq->where('users.id', $user_id)->where('userables.type', 'owner'));
            });
        }

        $events = $base
            ->with(['eventType', 'serviceOrders', 'executingUsers:id,name'])
            ->orderBy('start')
            ->get();

        return response()->json($events);
    }

    public function store(EventStoreRequest $request)
    {
        $data = $request->validated();
        unset($data['executing_user_ids']);
        $event = Event::create($data);

        $class = $request->eventable_type;
        $model = $class::findOrFail($request->eventable_id);
        $model->events()->attach($event->id);

        $executing_user_ids = $request['executing_user_ids'] ?? [];
        if (is_array($executing_user_ids) && count($executing_user_ids) > 0) {
            $event->syncExecutingUsers(array_map('intval', $executing_user_ids));
            $model->syncExecutingUsers(array_map('intval', $executing_user_ids));
            $model->serviceJobs()->each(function ($job) use ($executing_user_ids) {
                $job->syncExecutingUsers(array_map('intval', $executing_user_ids));
            });
        }

        return response()->json($event->load(['eventType','serviceOrders','executingUsers:id,name']), 201);
    }

    public function update(Request $request, Event $event)
    {
        $payload = $request->all();
        unset($payload['executing_user_ids']);
        $event->update($payload);

        $model = null;
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

        if ($request->has('executing_user_ids')) {
            $executing_user_ids = $request->input('executing_user_ids');
            if (is_array($executing_user_ids)) {
                $ids = array_map('intval', $executing_user_ids);
                $event->syncExecutingUsers($ids);
                if ($model) {
                    $model->syncExecutingUsers($ids);
                    $model->serviceJobs()->each(function ($job) use ($ids) {
                        $job->syncExecutingUsers($ids);
                    });
                } else {
                    $event->serviceOrders->each(function ($order) use ($ids) {
                        $order->syncExecutingUsers($ids);
                        $order->serviceJobs()->each(function ($job) use ($ids) {
                            $job->syncExecutingUsers($ids);
                        });
                    });
                }
            }
        }

        return response()->json($event->load(['eventType','serviceOrders','executingUsers:id,name']));
    }

    public function destroy(Event $event)
    {
        $event->delete();

        return response()->json(null, 204);
    }
}
