<?php

namespace App\Services;

use App\Domain\Planning\Clock;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceOrderEventWidget
{
    public function __construct(private EventLocationResolver $locations) {}

    /**
     * What a werkbon needs loaded before events() can show its appointments.
     *
     * @return array<int, string>
     */
    public static function relations(): array
    {
        return [
            'events.eventType',
            'events.executingUsers:id,name',
            'events.executions',
            ...EventLocationResolver::relations('events.'),
        ];
    }

    public function events(ServiceOrder $service_order, User $user): Collection
    {
        $can_see_all = $user->isAdmin() || $user->hasPermission('serviceorder.see_events_widget');
        $max_end = Event::horizonFor($user);

        $visible = $service_order->events
            ->filter(function ($event) use ($user, $can_see_all, $max_end) {
                $is_executing = $event->executingUsers->contains('id', $user->id);

                if (!$can_see_all && !$is_executing) {
                    return false;
                }

                return !$max_end || ($event->start && $event->start->lte($max_end));
            })
            ->sortBy('start')
            ->values();

        $roles_by_userable = $this->rolesByUserable($visible);

        return $visible
            ->map(fn ($event) => $this->shape($event, $roles_by_userable))
            ->values();
    }

    public function usersMissingTimes(ServiceOrder $service_order): Collection
    {
        return $service_order->events
            ->flatMap(fn ($event) => $event->executingUsers->filter(function ($executing_user) use ($event) {
                $execution = $event->executions->firstWhere('user_id', $executing_user->id);

                return !$execution || !$execution->actual_start || !$execution->actual_end;
            }))
            ->pluck('name')
            ->unique()
            ->values();
    }

    /**
     * Whether the order still has an appointment on a later local day, so whoever
     * asks the customer to sign is told the work is not done yet. Reads every
     * appointment, not only the ones the viewer may see in the widget.
     */
    public function plannedAfterToday(ServiceOrder $service_order): bool
    {
        return $service_order->events->contains(fn (Event $event) => !$event->isCancelled()
            && $event->start
            && Clock::toLocal($event->start)->toDateString() > Clock::today());
    }

    private function rolesByUserable(Collection $events): Collection
    {
        $pivot_ids = $events
            ->flatMap(fn ($event) => $event->executingUsers->pluck('pivot.id'))
            ->filter()
            ->all();

        return DB::table('user_role_userable')
            ->whereIn('userable_id', $pivot_ids)
            ->orderBy('user_role_id')
            ->get()
            ->groupBy('userable_id')
            ->map(fn ($rows) => $rows->pluck('user_role_id')->map(fn ($id) => (int) $id)->all());
    }

    private function shape($event, Collection $roles_by_userable): array
    {
        $executions_by_user = $event->executions->keyBy('user_id');
        $location = $this->locations->resolveWithSource($event);

        $executing_users = $event->executingUsers->map(function ($executing_user) use ($roles_by_userable, $executions_by_user) {
            $execution = $executions_by_user->get($executing_user->id);

            return [
                'id' => $executing_user->id,
                'name' => $executing_user->name,
                'user_role_ids' => $roles_by_userable->get($executing_user->pivot->id, []),
                'breaktime' => (int) $executing_user->pivot->breaktime,
                'completion_status' => $execution->completion_status ?? 'Gepland',
                'actual_start' => $execution?->actual_start,
                'actual_end' => $execution?->actual_end,
                'actual_breaktime_minutes' => $execution?->actual_breaktime_minutes,
                'travel_time_minutes' => $execution?->travel_time_minutes,
            ];
        })->values();

        return [
            'id' => $event->id,
            'name' => $event->name,
            'status' => $event->status,
            'start' => $event->start,
            'end' => $event->end,
            'location' => $location['address'],
            'location_source' => $location['source'],
            'event_type' => $event->eventType ? [
                'id' => $event->eventType->id,
                'name' => $event->eventType->name,
                'color' => $event->eventType->color,
            ] : null,
            'executing_users' => $executing_users,
        ];
    }
}
