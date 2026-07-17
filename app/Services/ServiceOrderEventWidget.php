<?php

namespace App\Services;

use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceOrderEventWidget
{
    public function events(ServiceOrder $service_order, User $user): Collection
    {
        $can_see_all = $user->isAdmin() || $user->hasPermission('serviceorder.see_events_widget');
        $can_see_beyond = $user->can('seeBeyondCurrentWeek', Event::class);
        $max_end = Carbon::now()->startOfDay()->addDays(7)->endOfDay();

        $visible = $service_order->events
            ->filter(function ($event) use ($user, $can_see_all, $can_see_beyond, $max_end) {
                $is_executing = $event->executingUsers->contains('id', $user->id);

                if (!$can_see_all && !$is_executing) {
                    return false;
                }

                return $can_see_beyond || ($event->start && $event->start->lte($max_end));
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

        $executing_users = $event->executingUsers->map(function ($executing_user) use ($roles_by_userable, $executions_by_user) {
            $execution = $executions_by_user->get($executing_user->id);

            return [
                'id' => $executing_user->id,
                'name' => $executing_user->name,
                'user_role_ids' => $roles_by_userable->get($executing_user->pivot->id, []),
                'completion_status' => $execution->completion_status ?? 'Gepland',
                'actual_start' => $execution?->actual_start,
                'actual_end' => $execution?->actual_end,
            ];
        })->values();

        return [
            'id' => $event->id,
            'name' => $event->name,
            'status' => $event->status,
            'start' => $event->start,
            'end' => $event->end,
            'location' => $event->display_location,
            'event_type' => $event->eventType ? [
                'id' => $event->eventType->id,
                'name' => $event->eventType->name,
                'color' => $event->eventType->color,
            ] : null,
            'executing_users' => $executing_users,
        ];
    }
}
