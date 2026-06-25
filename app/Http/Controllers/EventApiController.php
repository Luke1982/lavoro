<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventCopyRequest;
use App\Http\Requests\EventDestroyRequest;
use App\Http\Requests\EventReadRequest;
use App\Http\Requests\EventStoreRequest;
use App\Http\Requests\EventUpdateRequest;
use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Event;
use App\Models\GoogleSyncedEvent;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Notifications\NewServiceOrderAssigned;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EventApiController extends Controller
{
    public function index(EventReadRequest $request)
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
                'serviceOrders.project:id,title,location',
                'serviceOrders.taskInstances.serviceOrderTask',
                'serviceOrders.taskInstances.product.brand',
                'serviceOrders.taskInstances.product.productAttributeValueables.productAttribute',
                'serviceOrders.taskInstances.product.productAttributeValueables.value',
                'executingUsers',
                'executions',
            ])
            ->orderBy('start')
            ->get();

        return response()->json($this->withUserRoles($events));
    }

    public function store(EventStoreRequest $request)
    {
        $data = $request->validated();
        unset(
            $data['executing_user_ids'],
            $data['executing_user_breaktimes'],
            $data['executing_user_roles'],
            $data['executing_user_diverging_times'],
        );

        $eventable_id = $request->eventable_id;
        $eventable_type = $request->eventable_type;

        $notify_service_order = null;
        $notify_user_ids = [];

        $event = DB::transaction(
            function () use ($request, $data, &$eventable_id, &$eventable_type, &$notify_service_order, &$notify_user_ids) {
                if ($request->boolean('create_service_order')) {
                    $new_order = ServiceOrder::create(['customer_id' => $data['customer_id']]);
                    $eventable_type = '\\App\\Models\\ServiceOrder';
                    $eventable_id = $new_order->id;
                }

                unset($data['create_service_order'], $data['customer_id']);
                $data['eventable_type'] = $eventable_type;
                $data['eventable_id'] = $eventable_id;

                $event = Event::create($data);

                $model = $eventable_type::findOrFail($eventable_id);
                $model->events()->attach($event->id);
                if ($model instanceof ServiceOrder) {
                    $model->advanceToPlannedStage();
                }

                $executing_user_ids = $request['executing_user_ids'] ?? [];
                if (is_array($executing_user_ids) && count($executing_user_ids) > 0) {
                    $ids = array_map('intval', $executing_user_ids);
                    $raw_breaktimes = (array) ($request->input('executing_user_breaktimes', []));
                    $breaktimes = array_map('intval', $raw_breaktimes);
                    $user_roles = (array) ($request->input('executing_user_roles', []));
                    $diverging_times = (array) ($request->input('executing_user_diverging_times', []));
                    $event->syncExecutingUsers($ids, $breaktimes, $user_roles, $diverging_times);
                    $model->syncExecutingUsers($ids);
                    $model->serviceJobs()->each(fn ($job) => $job->syncExecutingUsers($ids));

                    if ($model instanceof ServiceOrder) {
                        $notify_service_order = $model;
                        $notify_user_ids = $ids;
                    }
                }

                return $event;
            }
        );

        if ($notify_service_order) {
            User::whereIn('id', $notify_user_ids)->get()
                ->each(fn ($user) => $user->notify(new NewServiceOrderAssigned($notify_service_order)));
        }

        $event->load([
            'eventType', 'serviceOrders.customer', 'serviceOrders.project:id,title,location',
            'executingUsers', 'executions',
        ]);

        return response()->json($this->withUserRoles($event), 201);
    }

    public function update(EventUpdateRequest $request, Event $event)
    {
        $payload = $request->validated();
        unset(
            $payload['executing_user_ids'],
            $payload['executing_user_breaktimes'],
            $payload['executing_user_roles'],
            $payload['executing_user_diverging_times'],
        );
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
                $raw_breaktimes = (array) ($request->input('executing_user_breaktimes', []));
                $breaktimes = array_map('intval', $raw_breaktimes);
                $user_roles = (array) ($request->input('executing_user_roles', []));
                $diverging_times = (array) ($request->input('executing_user_diverging_times', []));
                $event->syncExecutingUsers($ids, $breaktimes, $user_roles, $diverging_times);
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
                    $previously_executing = $model instanceof ServiceOrder
                        ? $model->executingUsers()->pluck('users.id')->all()
                        : [];

                    $model->syncExecutingUsers($ids);
                    $model->serviceJobs()->each(fn ($job) => $job->syncExecutingUsers($ids));

                    if ($model instanceof ServiceOrder) {
                        $new_ids = array_diff($ids, $previously_executing);
                        User::whereIn('id', $new_ids)->get()
                            ->each(fn ($user) => $user->notify(new NewServiceOrderAssigned($model)));
                    }
                } else {
                    $event->serviceOrders->each(function ($order) use ($ids) {
                        $previously_executing = $order->executingUsers()->pluck('users.id')->all();
                        $order->syncExecutingUsers($ids);
                        $order->serviceJobs()->each(fn ($job) => $job->syncExecutingUsers($ids));
                        $new_ids = array_diff($ids, $previously_executing);
                        User::whereIn('id', $new_ids)->get()
                            ->each(fn ($user) => $user->notify(new NewServiceOrderAssigned($order)));
                    });
                }
            }
        }

        $event->load([
            'eventType', 'serviceOrders.customer', 'serviceOrders.project:id,title,location',
            'executingUsers', 'executions',
        ]);

        return response()->json($this->withUserRoles($event));
    }

    public function destroy(EventDestroyRequest $request, Event $event)
    {
        $event->delete();

        return response()->json(null, 204);
    }

    public function copy(EventCopyRequest $request, Event $event)
    {
        $offsets = $request->validated()['offsets'];

        $service_orders = $event->serviceOrders()->get();
        $executing_user_ids = $event->executingUsers()->pluck('users.id')->all();
        $executing_user_roles = $this->executingUserRoleMap($event);

        $new_events = [];

        foreach ($offsets as $days) {
            $new_event = Event::create([
                'name' => $event->name,
                'description' => $event->description,
                'event_type_id' => $event->event_type_id,
                'status' => $event->status,
                'start' => $event->start->copy()->addDays($days),
                'end' => $event->end->copy()->addDays($days),
                'location' => $event->location,
                'is_preliminary' => $event->is_preliminary,
            ]);

            foreach ($service_orders as $order) {
                $order->events()->attach($new_event->id);
            }

            if (count($executing_user_ids) > 0) {
                $new_event->syncExecutingUsers($executing_user_ids, [], $executing_user_roles);
            }

            $new_event->load([
                'eventType', 'serviceOrders.customer', 'serviceOrders.project:id,title,location',
                'executingUsers', 'executions',
            ]);
            $new_events[] = $this->withUserRoles($new_event);
        }

        return response()->json($new_events, 201);
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
            'Afspraakbevestiging per e-mail verzonden naar: ' . implode(', ', $recipients)
        );

        return response()->json([
            'message' => 'Bevestiging verzonden naar: ' . implode(', ', $recipients),
        ]);
    }

    private function withUserRoles($events)
    {
        $collection = $events instanceof Collection ? $events : collect([$events]);

        $pivot_ids = $collection
            ->flatMap(fn ($event) => $event->executingUsers->pluck('pivot.id'))
            ->filter()
            ->all();

        $roles_by_userable = DB::table('user_role_userable')
            ->whereIn('userable_id', $pivot_ids)
            ->get()
            ->groupBy('userable_id')
            ->map(fn ($rows) => $rows->pluck('user_role_id')->map(fn ($id) => (int) $id)->all());

        foreach ($collection as $event) {
            $executions_by_user = $event->executions->keyBy('user_id');
            foreach ($event->executingUsers as $user) {
                $user->pivot->setAttribute(
                    'user_role_ids',
                    $roles_by_userable->get($user->pivot->id, [])
                );
                $user->pivot->setAttribute('has_diverging_times', (bool) ($user->pivot->has_diverging_times ?? false));
                $user->pivot->setAttribute('diverging_start', $user->pivot->diverging_start);
                $user->pivot->setAttribute('diverging_end', $user->pivot->diverging_end);
                $execution = $executions_by_user->get($user->id);
                $user->pivot->setAttribute('completion_status', $execution->completion_status ?? 'Gepland');
                $user->pivot->setAttribute('actual_start', $execution?->actual_start);
                $user->pivot->setAttribute('actual_end', $execution?->actual_end);
                $user->pivot->setAttribute('has_signature', filled($execution?->signature_base64));
            }
        }

        return $events;
    }

    private function executingUserRoleMap(Event $event): array
    {
        return DB::table('userables')
            ->join('user_role_userable', 'userables.id', '=', 'user_role_userable.userable_id')
            ->where('userables.userable_type', $event->getMorphClass())
            ->where('userables.userable_id', $event->getKey())
            ->where('userables.type', 'executing')
            ->get(['userables.user_id', 'user_role_userable.user_role_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('user_role_id')->map(fn ($id) => (int) $id)->all())
            ->toArray();
    }
}
