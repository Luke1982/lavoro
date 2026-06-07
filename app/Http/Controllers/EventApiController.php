<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventDestroyRequest;
use App\Http\Requests\EventStoreRequest;
use App\Http\Requests\EventUpdateRequest;
use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Event;
use App\Models\GoogleSyncedEvent;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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

        if (! $has_all && $user_id) {
            $base->where(function ($q) use ($user_id) {
                $q->whereHas('executingUsers', fn ($sq) => $sq->where('users.id', $user_id))
                    ->orWhereHas('owners', fn ($sq) => $sq->where('users.id', $user_id)->where('userables.type', 'owner'));
            });
        }

        $events = $base
            ->with([
                'eventType',
                'serviceOrders.customer',
                'serviceOrders.taskInstances.serviceOrderTask',
                'executingUsers:id,name',
            ])
            ->orderBy('start')
            ->get();

        return response()->json($events);
    }

    public function store(EventStoreRequest $request)
    {
        $data = $request->validated();
        unset($data['executing_user_ids']);

        $eventable_id   = $request->eventable_id;
        $eventable_type = $request->eventable_type;

        $event = DB::transaction(function () use ($request, $data, &$eventable_id, &$eventable_type) {
            if ($request->boolean('create_service_order')) {
                $new_order      = ServiceOrder::create(['customer_id' => $data['customer_id']]);
                $eventable_type = '\\App\\Models\\ServiceOrder';
                $eventable_id   = $new_order->id;
            }

            unset($data['create_service_order'], $data['customer_id']);
            $data['eventable_type'] = $eventable_type;
            $data['eventable_id']   = $eventable_id;

            $event = Event::create($data);

            $model = $eventable_type::findOrFail($eventable_id);
            $model->events()->attach($event->id);
            if ($model instanceof ServiceOrder) {
                $model->advanceToPlannedStage();
            }

            $executing_user_ids = $request['executing_user_ids'] ?? [];
            if (is_array($executing_user_ids) && count($executing_user_ids) > 0) {
                $event->syncExecutingUsers(array_map('intval', $executing_user_ids));
                $model->syncExecutingUsers(array_map('intval', $executing_user_ids));
                $model->serviceJobs()->each(function ($job) use ($executing_user_ids) {
                    $job->syncExecutingUsers(array_map('intval', $executing_user_ids));
                });
            }

            return $event;
        });

        return response()->json($event->load(['eventType', 'serviceOrders', 'executingUsers:id,name']), 201);
    }

    public function update(EventUpdateRequest $request, Event $event)
    {
        $payload = $request->validated();
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
            if ($model instanceof ServiceOrder) {
                $model->advanceToPlannedStage();
            }
        }

        if ($request->has('executing_user_ids')) {
            $executing_user_ids = $request->input('executing_user_ids');
            if (is_array($executing_user_ids)) {
                $ids = array_map('intval', $executing_user_ids);
                $event->syncExecutingUsers($ids);
                PushEventJob::dispatch($event->id);
                $still_relevant = array_unique(array_merge(
                    $event->owners()->wherePivot('type', 'owner')->pluck('users.id')->all(),
                    $event->executingUsers()->pluck('users.id')->all(),
                ));
                GoogleSyncedEvent::whereHas(
                    'syncedCalendar',
                    fn ($q) => $q->whereNotIn('owner_user_id', $still_relevant),
                )->where('event_id', $event->id)->get()
                    ->each(fn ($m) => DeleteEventFromGoogleJob::dispatch(
                        $m->id,
                        $m->google_synced_calendar_id,
                        $m->google_event_id,
                    ));
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

        return response()->json($event->load(['eventType', 'serviceOrders', 'executingUsers:id,name']));
    }

    public function destroy(EventDestroyRequest $request, Event $event)
    {
        $event->delete();

        return response()->json(null, 204);
    }

    public function sendConfirmation(Event $event)
    {
        $service_order = $event->serviceOrders()
            ->with(['customer', 'taskInstances.serviceOrderTask'])
            ->first();

        if (! $service_order) {
            return response()->json(['message' => 'Geen werkbon gekoppeld aan deze afspraak.'], 422);
        }

        $recipients = array_unique(array_filter([
            $service_order->customer?->email,
            $service_order->customer?->invoice_email,
        ]));

        if (empty($recipients)) {
            return response()->json(['message' => 'Klant heeft geen e-mailadres.'], 422);
        }

        Mail::to($recipients)->send(new AppointmentConfirmationMail($event, $service_order));

        $service_order->logActivity(
            'Afspraakbevestiging per e-mail verzonden naar: '.implode(', ', $recipients)
        );

        return response()->json([
            'message' => 'Bevestiging verzonden naar: '.implode(', ', $recipients),
        ]);
    }
}
