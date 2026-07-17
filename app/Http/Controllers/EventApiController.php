<?php

namespace App\Http\Controllers;

use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Http\Requests\EventCopyRequest;
use App\Http\Requests\EventDestroyRequest;
use App\Http\Requests\EventFeedbackRequest;
use App\Http\Requests\EventReadRequest;
use App\Http\Requests\EventSearchRequest;
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
use App\Services\StandardEmailTriggerResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EventApiController extends Controller
{
    public function index(EventReadRequest $request)
    {
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

        $base->visibleTo(Auth::user());

        $events = $base
            ->with([
                ...$this->baseEventRelations(),
                'serviceOrders.project:id,title,location',
                'serviceOrders.taskInstances.serviceOrderTask',
                'serviceOrders.taskInstances.product.brand',
                'serviceOrders.taskInstances.product.productAttributeValueables.productAttribute',
                'serviceOrders.taskInstances.product.productAttributeValueables.value',
                'executingUsers',
                'executions',
            ])
            ->withCount(['remarks', 'images'])
            ->orderBy('start')
            ->get();

        $events->each->append('display_location');

        return response()->json($this->withUserRoles($events));
    }

    public function search(EventSearchRequest $request)
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $base = Event::query()->visibleTo(Auth::user());

        if (!$request->user()?->can('seeBeyondCurrentWeek', Event::class)) {
            $base->where('start', '<=', Carbon::now()->startOfDay()->addDays(7)->endOfDay());
        }

        $is_numeric_q = is_numeric($q);

        $base->where(function ($query) use ($q, $is_numeric_q) {
            $query->where('location', 'like', "%{$q}%")
                ->orWhereHas('linkedLocation', fn ($lq) => $lq->where('title', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%")
                    ->orWhere('city', 'like', "%{$q}%"))
                ->orWhereHas('customers', fn ($sq) => $sq->where('name', 'like', "%{$q}%"))
                ->orWhereHas('serviceOrders', function ($sq) use ($q, $is_numeric_q) {
                    $sq->where(function ($ssq) use ($q, $is_numeric_q) {
                        if ($is_numeric_q) {
                            $ssq->orWhere('service_orders.id', $q);
                        }
                        $ssq->orWhere('external_purchaseorder_no', 'like', "%{$q}%")
                            ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$q}%"))
                            ->orWhereHas('project', function ($pq) use ($q, $is_numeric_q) {
                                $pq->where('title', 'like', "%{$q}%");
                                if ($is_numeric_q) {
                                    $pq->orWhere('id', $q);
                                }
                            });
                    });
                });
        });

        $events = $base
            ->with([
                ...$this->baseEventRelations(),
                'serviceOrders.project:id,title',
                'executingUsers:id,name',
            ])
            ->orderByDesc('start')
            ->limit(8)
            ->get();

        return response()->json($events->map(fn ($event) => $this->searchResultShape($event)));
    }

    private function baseEventRelations(): array
    {
        return ['eventType', 'serviceOrders.customer', 'customers'];
    }

    private function searchResultShape(Event $event): array
    {
        return [
            'id' => $event->id,
            'start' => $event->start,
            'location' => $event->resolved_location,
            'description' => $event->description,
            'event_type_name' => $event->eventType?->name,
            'color' => $event->eventType?->color ?? '#3b82f6',
            'customer_name' => $event->serviceOrders->first()?->customer?->name ?? $event->customers->first()?->name,
            'project_name' => $event->serviceOrders->first()?->project?->title,
            'service_order_id' => $event->serviceOrders->first()?->id,
            'executing_users' => $event->executingUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ];
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

                $no_service_order = $request->boolean('no_service_order');
                $customer_id = $data['customer_id'] ?? null;

                unset($data['create_service_order'], $data['customer_id']);
                $data['eventable_type'] = $no_service_order ? null : $eventable_type;
                $data['eventable_id'] = $no_service_order ? null : $eventable_id;

                $event = Event::create($data);

                $model = null;
                if (!$no_service_order) {
                    $model = $eventable_type::findOrFail($eventable_id);
                    $model->events()->attach($event->id);
                    if ($model instanceof ServiceOrder) {
                        $model->advanceToPlannedStage();
                    }
                } elseif ($customer_id) {
                    $event->customers()->attach($customer_id);
                }

                $executing_user_ids = $request['executing_user_ids'] ?? [];
                if (is_array($executing_user_ids) && count($executing_user_ids) > 0) {
                    $ids = array_map('intval', $executing_user_ids);
                    $raw_breaktimes = (array) ($request->input('executing_user_breaktimes', []));
                    $breaktimes = array_map('intval', $raw_breaktimes);
                    $user_roles = (array) ($request->input('executing_user_roles', []));
                    $diverging_times = (array) ($request->input('executing_user_diverging_times', []));
                    $event->syncExecutingUsers($ids, $breaktimes, $user_roles, $diverging_times);
                    if ($model) {
                        $model->syncExecutingUsers($ids);
                        $model->serviceJobs()->each(fn ($job) => $job->syncExecutingUsers($ids));
                        if ($model instanceof ServiceOrder) {
                            $notify_service_order = $model;
                            $notify_user_ids = $ids;
                        }
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
            'executingUsers', 'executions', 'customers',
        ]);

        return response()->json(array_merge(
            $this->withUserRoles($event)->toArray(),
            [
                'pending_standard_emails' => $this->pendingStandardEmails($event, EventTrigger::event_created),
                'queued_standard_emails' => $this->queuedStandardEmailNames($event, EventTrigger::event_created),
            ]
        ), 201);
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

        $customer_id = $payload['customer_id'] ?? null;
        unset($payload['customer_id'], $payload['create_service_order']);

        $event->update($payload);

        $create_new = $request->boolean('create_service_order');
        $eventable_type = $create_new ? '\\App\\Models\\ServiceOrder' : $request->eventable_type;
        $eventable_id = $create_new ? null : $request->eventable_id;
        $linking = $create_new || ($eventable_type && $eventable_id);

        if ($event->no_service_order && !$linking) {
            $event->customers()->sync($customer_id ? [$customer_id] : []);
        }

        $model = null;
        if ($linking) {
            $model = DB::transaction(function () use (
                $event,
                $create_new,
                $customer_id,
                $eventable_type,
                $eventable_id
            ) {
                if ($create_new) {
                    $eventable_id = ServiceOrder::create(['customer_id' => $customer_id])->id;
                }

                $model = $eventable_type::findOrFail($eventable_id);

                DB::table('eventables')
                    ->where('event_id', $event->id)
                    ->where('eventable_type', substr($eventable_type, 1))
                    ->delete();

                $model->events()->attach($event->id);
                if ($model instanceof ServiceOrder) {
                    $model->advanceToPlannedStage();
                }

                if ($event->no_service_order) {
                    $event->customers()->detach();
                    $event->update(['no_service_order' => false]);
                }

                return $model;
            });
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
            'executingUsers', 'executions', 'customers',
        ]);

        return response()->json(array_merge(
            $this->withUserRoles($event)->toArray(),
            [
                'pending_standard_emails' => $this->pendingStandardEmails($event, EventTrigger::event_updated),
                'queued_standard_emails' => $this->queuedStandardEmailNames($event, EventTrigger::event_updated),
            ]
        ));
    }

    public function destroy(EventDestroyRequest $request, Event $event)
    {
        $event->load(['serviceOrders.customer', 'customers']);
        $pending = $this->pendingStandardEmails($event, EventTrigger::event_deleted);

        $event->delete();

        return response()->json(['pending_standard_emails' => $pending]);
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
                'location_id' => $event->location_id,
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

        if (!$service_order) {
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

    public function feedback(EventFeedbackRequest $request, Event $event)
    {
        $event->load(['remarks.user', 'images']);

        return response()->json([
            'remarks' => $event->remarks,
            'images' => $event->images,
        ]);
    }

    private function pendingStandardEmails(Event $event, EventTrigger $trigger): array
    {
        return StandardEmailTriggerResolver::matching(
            $event,
            $trigger,
            [StandardEmailTriggerType::confirm->name, StandardEmailTriggerType::allowedit->name]
        )->map(fn ($match) => [
            'standard_email_id' => $match->standard_email_id,
            'name' => $match->standardEmail->name,
            'trigger' => $trigger->name,
            'trigger_type' => $match->trigger_type,
        ])->values()->all();
    }

    private function queuedStandardEmailNames(Event $event, EventTrigger $trigger): array
    {
        return StandardEmailTriggerResolver::matching(
            $event,
            $trigger,
            [StandardEmailTriggerType::background->name]
        )->map(fn ($match) => $match->standardEmail->name)->values()->all();
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
            ->orderBy('user_role_id')
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
                $user->pivot->setAttribute('travel_time_minutes', (int) ($execution?->travel_time_minutes ?? 0));
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
