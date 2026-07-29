<?php

namespace App\Domain\Planning;

use App\Models\Event;
use App\Models\GeneralSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * When a mechanic has room, read out of the diary rather than worked out by eye.
 *
 * This turns appointments and unavailability into the bands DaySegments works
 * on, and does nothing clever with them afterwards — the arithmetic lives there,
 * shared with the planner's own copy, so the two cannot drift apart.
 */
class TechnicianAvailability
{
    private const MINUTES_PER_DAY = 1440;

    public function workStartHour(): int
    {
        return (int) GeneralSetting::get('planner_day_start_hour', 7);
    }

    public function workEndHour(): int
    {
        return (int) GeneralSetting::get('planner_day_end_hour', 18);
    }

    /**
     * The first stretch from $from onwards that is long enough for the job, or
     * null if there is none inside $days.
     *
     * Searching forward a day at a time rather than loading a fortnight at once:
     * the answer is usually in the first day or two, and a planner asking about
     * next week wants the first thing that fits, not a calendar.
     *
     * @return array{date: string, start_min: int, end_min: int}|null
     */
    public function firstSlot(User $user, CarbonImmutable $from, int $duration_minutes, int $days = 14): ?array
    {
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $from->addDays($offset);

            foreach ($this->freeSegments($user, $day) as $segment) {
                if ($duration_minutes <= $segment['end_min'] - $segment['start_min']) {
                    return [
                        'date' => $day->toDateString(),
                        'start_min' => $segment['start_min'],
                        'end_min' => $segment['end_min'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array{kind: string, start_min: int, end_min: int, label: ?string}>
     */
    public function freeSegments(User $user, CarbonImmutable $day): array
    {
        $segments = DaySegments::for(
            work_start_hour: $this->workStartHour(),
            work_end_hour: $this->workEndHour(),
            busy: $this->busyBands($user, $day),
            blocked: $this->blockedBands($user, $day),
        );

        return array_values(array_filter($segments, fn (array $segment) => $segment['kind'] === 'free'));
    }

    /**
     * An appointment counts against the day it actually covers, so one running
     * across midnight blocks only its own hours on each side rather than the
     * whole of both days.
     *
     * @return array<int, array{start_min: int, end_min: int}>
     */
    private function busyBands(User $user, CarbonImmutable $day): array
    {
        $start = $day->startOfDay();
        $end = $day->addDay()->startOfDay();

        $events = Event::query()
            ->whereHas('executingUsers', fn ($q) => $q->where('users.id', $user->id))
            ->where('start', '<', $end)
            ->where('end', '>', $start)
            ->with(['executingUsers' => fn ($q) => $q->where('users.id', $user->id)])
            ->get();

        return $events->map(function (Event $event) use ($user, $start) {
            $event_start = CarbonImmutable::parse($event->start);
            $event_end = CarbonImmutable::parse($event->end);

            /**
             * Diverging times are clock times on one day, so they cannot describe
             * a span. An appointment crossing midnight is clamped to the day
             * instead, which is what the planner does with it too.
             */
            if (!$event_start->isSameDay($event_end)) {
                return [
                    'start_min' => max(0, (int) $start->diffInMinutes($event_start, false)),
                    'end_min' => min(self::MINUTES_PER_DAY, (int) $start->diffInMinutes($event_end, false)),
                ];
            }

            $pivot = $event->executingUsers->firstWhere('id', $user->id)?->pivot;

            if ($pivot && $pivot->has_diverging_times && $pivot->diverging_start && $pivot->diverging_end) {
                return [
                    'start_min' => $this->minutesFromTime($pivot->diverging_start),
                    'end_min' => $this->minutesFromTime($pivot->diverging_end),
                ];
            }

            return [
                'start_min' => $event_start->hour * 60 + $event_start->minute,
                'end_min' => $event_end->hour * 60 + $event_end->minute,
            ];
        })->all();
    }

    /**
     * @return array<int, array{start_min: int, end_min: int, label: ?string}>
     */
    private function blockedBands(User $user, CarbonImmutable $day): array
    {
        return $user->unavailabilities
            ->filter(fn ($unavailability) => $this->appliesOn($unavailability, $day))
            ->map(fn ($unavailability) => [
                /** No times at all means the whole day is gone. */
                'start_min' => $unavailability->start_time === null
                    ? 0
                    : $this->minutesFromTime($unavailability->start_time),
                'end_min' => $unavailability->start_time === null
                    ? self::MINUTES_PER_DAY
                    : $this->minutesFromTime($unavailability->end_time),
                'label' => $unavailability->label,
            ])
            ->values()
            ->all();
    }

    private function appliesOn(mixed $unavailability, CarbonImmutable $day): bool
    {
        if ($unavailability->type === 'holiday') {
            $from = CarbonImmutable::parse($unavailability->date)->startOfDay();
            $until = CarbonImmutable::parse($unavailability->end_date ?? $unavailability->date)->endOfDay();

            return $day->betweenIncluded($from, $until);
        }

        if ($unavailability->type !== 'recurring') {
            return false;
        }

        /** The column counts Monday as 0; Carbon counts Sunday as 0. */
        if (((int) $day->dayOfWeek + 6) % 7 !== (int) $unavailability->day_of_week) {
            return false;
        }

        if ($unavailability->repeat === 'biweekly' && $unavailability->reference_date) {
            $reference = CarbonImmutable::parse($unavailability->reference_date)->startOfWeek();

            return abs($reference->diffInWeeks($day->startOfWeek())) % 2 === 0;
        }

        return true;
    }

    private function minutesFromTime(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hours * 60 + $minutes;
    }

    /** @return Collection<int, User> */
    public function plannableUsers(): Collection
    {
        return User::query()
            ->where('plannable', true)
            ->with(['planGroups:id,name', 'unavailabilities'])
            ->orderBy('name')
            ->get();
    }
}
