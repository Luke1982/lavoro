<?php

namespace App\Http\Controllers;

use App\Actions\Appointments\AppointmentChanges;
use App\Actions\Appointments\CancelAppointmentAction;
use App\Actions\Appointments\CreateAppointmentAction;
use App\Actions\Appointments\NewAppointment;
use App\Actions\Appointments\UpdateAppointmentAction;
use App\Domain\Signals\ServiceOrders\AppointmentConfirmationEmailed;
use App\Domain\Signals\Signals;
use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Http\Requests\EventCopyRequest;
use App\Http\Requests\EventDestroyRequest;
use App\Http\Requests\EventFeedbackRequest;
use App\Http\Requests\EventReadRequest;
use App\Http\Requests\EventSearchRequest;
use App\Http\Requests\EventStoreRequest;
use App\Http\Requests\EventUpdateRequest;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Event;
use App\Services\EventLocationResolver;
use App\Services\StandardEmailTriggerResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EventApiController extends Controller
{
    /** Punctuation an address may be written with, stripped before matching. */
    private const ADDRESS_SEPARATORS = [' ', ',', '.', '-', '/', "'"];

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

        $events->each->append(['display_location', 'has_deviating_location', 'inherited_location']);

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

        $address_like = '%' . $this->normalizeAddressTerm($q) . '%';

        $base->where(function ($query) use ($q, $is_numeric_q, $address_like) {
            $query->where('name', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")
                ->orWhereRaw($this->addressMatchSql('events.location'), [$address_like])
                ->orWhereHas('linkedLocation', fn ($lq) => $this->matchLocation($lq, $q, $address_like))
                ->orWhereHas('customers', fn ($sq) => $this->matchCustomer($sq, $q, $address_like))
                ->orWhereHas('serviceOrders', function ($sq) use ($q, $is_numeric_q, $address_like) {
                    $sq->where(function ($ssq) use ($q, $is_numeric_q, $address_like) {
                        if ($is_numeric_q) {
                            $ssq->orWhere('service_orders.id', $q);
                        }
                        $ssq->orWhere('external_purchaseorder_no', 'like', "%{$q}%")
                            ->orWhereRaw($this->addressMatchSql('service_orders.execution_location'), [$address_like])
                            ->orWhereHas('linkedLocation', fn ($lq) => $this->matchLocation($lq, $q, $address_like))
                            ->orWhereHas('customer', fn ($cq) => $this->matchCustomer($cq, $q, $address_like))
                            ->orWhereHas('project', function ($pq) use ($q, $is_numeric_q, $address_like) {
                                $pq->where('title', 'like', "%{$q}%")
                                    ->orWhereRaw($this->addressMatchSql('projects.location'), [$address_like]);
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
        return ['eventType', ...EventLocationResolver::relations()];
    }

    /**
     * Every rung of the EventLocationResolver escalation is a place the address
     * may actually live, so searching an address has to reach all of them.
     */
    private function matchLocation(Builder $query, string $q, string $address_like): void
    {
        $query->where('locations.title', 'like', "%{$q}%")
            ->orWhereRaw(
                $this->addressMatchSql('locations.address', 'locations.postal_code', 'locations.city'),
                [$address_like]
            );
    }

    private function matchCustomer(Builder $query, string $q, string $address_like): void
    {
        $query->where('customers.name', 'like', "%{$q}%")
            ->orWhereRaw(
                $this->addressMatchSql('customers.address', 'customers.postal_code', 'customers.city'),
                [$address_like]
            );
    }

    /**
     * An address is stored across separate columns but typed — or pasted — as one
     * line, so the separators are stripped from both sides before comparing. That
     * lets "Stationsweg 45, 3011 CE Rotterdam" match a row holding those three
     * parts separately, and lets "3011CE" match a stored "3011 CE".
     *
     * Built from replace() rather than regexp_replace() so it runs on the SQLite
     * the test suite uses as well as on MySQL.
     */
    private function addressMatchSql(string ...$columns): string
    {
        $expression = count($columns) === 1
            ? "lower({$columns[0]})"
            : 'lower(concat_ws(\' \', ' . implode(', ', $columns) . '))';

        foreach (self::ADDRESS_SEPARATORS as $separator) {
            $literal = "'" . str_replace("'", "''", $separator) . "'";
            $expression = "replace({$expression}, {$literal}, '')";
        }

        return "{$expression} like ?";
    }

    /**
     * Strips exactly what addressMatchSql() strips, so the two sides can never
     * disagree. Degrades to '-' rather than '' for a query holding only
     * separators: a stripped column can never contain a dash, where '' would
     * match every row. A dash is not a LIKE wildcard either.
     */
    private function normalizeAddressTerm(string $q): string
    {
        return mb_strtolower(str_replace(self::ADDRESS_SEPARATORS, '', $q)) ?: '-';
    }

    private function searchResultShape(Event $event): array
    {
        return [
            'id' => $event->id,
            'start' => $event->start,
            'location' => $event->display_location,
            'event_name' => $event->name,
            'description' => $event->description,
            'is_preliminary' => $event->is_preliminary,
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
        $validated = $request->validated();

        $event = app(CreateAppointmentAction::class)->execute(
            NewAppointment::fromPayload($validated, [
                ...$validated,
                'create_service_order' => $request->boolean('create_service_order'),
                'no_service_order' => $request->boolean('no_service_order'),
            ])
        );

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
        app(UpdateAppointmentAction::class)->execute(
            $event,
            AppointmentChanges::fromPayload($request->validated(), [
                ...$request->all(),
                'create_service_order' => $request->boolean('create_service_order'),
                'eventable_provided' => $request->has('eventable_id'),
            ])
        );

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

        app(CancelAppointmentAction::class)->execute($event);

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

        Signals::dispatch(new AppointmentConfirmationEmailed($service_order, $event, $recipients));

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
