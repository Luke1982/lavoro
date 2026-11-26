<?php

namespace App\Http\Controllers;

use App\Actions\Appointments\AppointmentChanges;
use App\Actions\Appointments\CancelAppointmentAction;
use App\Actions\Appointments\CopyAppointmentAction;
use App\Actions\Appointments\CreateAppointmentAction;
use App\Actions\Appointments\MoveAppointmentAction;
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
use App\Http\Requests\EventSendConfirmationRequest;
use App\Http\Requests\EventStoreRequest;
use App\Http\Requests\EventUpdateRequest;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Event;
use App\Models\Project;
use App\Services\EventLocationResolver;
use App\Services\StandardEmailTriggerResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
                ...Project::relationsForAddress('serviceOrders.project'),
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

        $events = Event::query()
            ->visibleTo($request->user())
            ->withinHorizonOf($request->user())
            ->matchesSearch($q)
            ->with([
                ...$this->baseEventRelations(),
                ...Project::relationsForAddress('serviceOrders.project'),
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
            'customer_name' => $event->customerName(),
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
            'eventType', 'serviceOrders.customer', ...Project::relationsForAddress('serviceOrders.project'),
            'executingUsers', 'executions', 'customers',
        ]);

        return response()->json(array_merge(
            $this->oneWithUserRoles($event),
            [
                'pending_standard_emails' => $this->pendingStandardEmails($event, EventTrigger::event_created),
                'queued_standard_emails' => $this->queuedStandardEmailNames($event, EventTrigger::event_created),
            ]
        ), 201);
    }

    public function update(EventUpdateRequest $request, Event $event)
    {
        if ($request->changesOnlyTheTimes()) {
            app(MoveAppointmentAction::class)->execute($event, ...$request->times($event));
        } else {
            app(UpdateAppointmentAction::class)->execute(
                $event,
                AppointmentChanges::fromPayload($request->validated(), [
                    ...$request->all(),
                    'create_service_order' => $request->boolean('create_service_order'),
                    'eventable_provided' => $request->has('eventable_id'),
                ])
            );
        }

        $event->load([
            'eventType', 'serviceOrders.customer', ...Project::relationsForAddress('serviceOrders.project'),
            'executingUsers', 'executions', 'customers',
        ]);

        return response()->json(array_merge(
            $this->oneWithUserRoles($event),
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
        $new_events = new EloquentCollection(app(CopyAppointmentAction::class)->execute($event, $request->offsets()));

        $new_events->load([
            'eventType', 'serviceOrders.customer', ...Project::relationsForAddress('serviceOrders.project'),
            'executingUsers', 'executions',
        ]);

        return response()->json($this->withUserRoles($new_events), 201);
    }

    public function sendConfirmation(EventSendConfirmationRequest $request, Event $event)
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

    /**
     * The standard e-mails the planner offers to send after this change, only
     * to whoever may read and send them; anybody else would open a preview
     * that refuses them.
     */
    private function pendingStandardEmails(Event $event, EventTrigger $trigger): array
    {
        if (!Auth::user()?->can('sendStandardEmail', $event)) {
            return [];
        }

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

    /**
     * The appointment as the planner and its edit dialog receive it: each crew
     * member with their plan roles and execution, and whether the person
     * looking may change it, remove it or mail the customer about it, answered
     * by EventPolicy so the screen never works out a rule of its own.
     *
     * @return array<int, array<string, mixed>>
     */
    private function withUserRoles(Collection $events): array
    {
        $viewer = Auth::user();

        (new EloquentCollection($events->all()))->loadMissing('owners');

        $pivot_ids = $events
            ->flatMap(fn ($event) => $event->executingUsers->pluck('pivot.id'))
            ->filter()
            ->all();

        $roles_by_userable = DB::table('user_role_userable')
            ->whereIn('userable_id', $pivot_ids)
            ->orderBy('user_role_id')
            ->get()
            ->groupBy('userable_id')
            ->map(fn ($rows) => $rows->pluck('user_role_id')->map(fn ($id) => (int) $id)->all());

        return $events->map(function (Event $event) use ($viewer, $roles_by_userable) {
            $executions_by_user = $event->executions->keyBy('user_id');

            foreach ($event->executingUsers as $crew_member) {
                $crew_member->pivot->setAttribute(
                    'user_role_ids',
                    $roles_by_userable->get($crew_member->pivot->id, [])
                );
                $crew_member->pivot->setAttribute('has_diverging_times', (bool) ($crew_member->pivot->has_diverging_times ?? false));
                $crew_member->pivot->setAttribute('diverging_start', $crew_member->pivot->diverging_start);
                $crew_member->pivot->setAttribute('diverging_end', $crew_member->pivot->diverging_end);
                $execution = $executions_by_user->get($crew_member->id);
                $crew_member->pivot->setAttribute('completion_status', $execution->completion_status ?? 'Gepland');
                $crew_member->pivot->setAttribute('actual_start', $execution?->actual_start);
                $crew_member->pivot->setAttribute('actual_end', $execution?->actual_end);
                $crew_member->pivot->setAttribute('travel_time_minutes', (int) ($execution?->travel_time_minutes ?? 0));
                $crew_member->pivot->setAttribute('has_signature', filled($execution?->signature_base64));
            }

            return [
                ...$event->makeHidden('owners')->toArray(),
                'can_update' => (bool) $viewer?->can('update', $event),
                'can_delete' => (bool) $viewer?->can('delete', $event),
                'can_send_standard_email' => (bool) $viewer?->can('sendStandardEmail', $event),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function oneWithUserRoles(Event $event): array
    {
        return $this->withUserRoles(collect([$event]))[0];
    }
}
