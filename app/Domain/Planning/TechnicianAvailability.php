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
 *
 * Answering "who is free in the next fortnight" means asking about ten or so
 * people across fourteen days, so the diary is read once for the whole window
 * rather than once per person per day. Done naively that is several hundred
 * queries for one question, and the shape of it hides well: it only shows up
 * when people are actually busy, because an empty diary is answered on the
 * first day and stops.
 */
class TechnicianAvailability
{
    private const MINUTES_PER_DAY = 1440;

    private ?int $work_start_hour = null;

    private ?int $work_end_hour = null;

    /** @var array<string, Collection<int, Event>> Events per user, for one loaded window. */
    private array $diary = [];

    private ?string $diary_key = null;

    /**
     * Drops the window it is holding.
     *
     * This instance lives for the whole request, which is what keeps a planning
     * question down to ten queries — and it is exactly what makes the diary go
     * stale the moment something is written to it. Booking an appointment and
     * then asking whether that mechanic is free would otherwise be answered out
     * of the copy read before the booking, and cheerfully double-book them.
     */
    public function forget(): void
    {
        $this->diary = [];
        $this->diary_key = null;
    }

    public function workStartHour(): int
    {
        return $this->work_start_hour ??= (int) GeneralSetting::get('planner_day_start_hour', 7);
    }

    public function workEndHour(): int
    {
        return $this->work_end_hour ??= (int) GeneralSetting::get('planner_day_end_hour', 18);
    }

    /**
     * The first stretch long enough for the job, per person, over one window.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array{date: string, start_min: int, end_min: int}|null>
     */
    public function firstSlots(Collection $users, CarbonImmutable $from, int $duration_minutes, int $days = 14): array
    {
        $this->loadDiary($users, $from, $days);

        $slots = [];

        foreach ($users as $user) {
            $slots[$user->id] = $this->firstSlotFromDiary($user, $from, $duration_minutes, $days);
        }

        return $slots;
    }

    /** @return array{date: string, start_min: int, end_min: int}|null */
    public function firstSlot(User $user, CarbonImmutable $from, int $duration_minutes, int $days = 14): ?array
    {
        return $this->firstSlots(collect([$user]), $from, $duration_minutes, $days)[$user->id];
    }

    /** @return array{date: string, start_min: int, end_min: int}|null */
    private function firstSlotFromDiary(User $user, CarbonImmutable $from, int $duration_minutes, int $days): ?array
    {
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $from->addDays($offset);

            foreach ($this->segmentsFrom($user, $day) as $segment) {
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
     * Every free stretch, per person, per day, for one window.
     *
     * Planning a crew means asking about the same day for several people at once,
     * so the whole grid is built from one read of the diary. Asking per person per
     * day instead is the query storm this class exists to avoid.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, array<int, array{start_min: int, end_min: int}>>>
     */
    public function segmentsFor(
        Collection $users,
        CarbonImmutable $from,
        int $days,
        bool $ignore_time_off = false,
    ): array {
        $this->loadDiary($users, $from, $days);

        $grid = [];

        foreach ($users as $user) {
            for ($offset = 0; $offset < $days; $offset++) {
                $day = $from->addDays($offset);
                $grid[$user->id][$day->toDateString()] = $this->segmentsFrom($user, $day, $ignore_time_off);
            }
        }

        return $grid;
    }

    /**
     * What each person has recorded as time off, per day, by the name they gave it.
     *
     * A day off can be overridden after somebody agrees to it, so the planner has
     * to be able to say whose day it is and what they called it. "Dit kan, maar
     * dan gaat Marvins papadag eraan" is a question worth asking; quietly booking
     * over it is not.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, array<int, string>>>
     */
    public function timeOffFor(Collection $users, CarbonImmutable $from, int $days): array
    {
        $time_off = [];

        foreach ($users as $user) {
            for ($offset = 0; $offset < $days; $offset++) {
                $day = $from->addDays($offset);
                $bands = $this->blockedBands($user, $day);

                if ($bands === []) {
                    continue;
                }

                $time_off[$user->id][$day->toDateString()] = array_values(array_unique(array_map(
                    fn (array $band) => $band['label'] ?: 'vrije dag',
                    $bands,
                )));
            }
        }

        return $time_off;
    }

    /**
     * @return array<int, array{kind: string, start_min: int, end_min: int, label: ?string}>
     */
    public function freeSegments(User $user, CarbonImmutable $day): array
    {
        $this->loadDiary(collect([$user]), $day, 1);

        return $this->segmentsFrom($user, $day);
    }

    /**
     * The same thing for a diary already in hand.
     *
     * Kept separate because the public method loads one user for one day, and
     * calling it from the loop would throw away the window just read and put the
     * per-day query storm straight back.
     *
     * @return array<int, array{kind: string, start_min: int, end_min: int, label: ?string}>
     */
    private function segmentsFrom(User $user, CarbonImmutable $day, bool $ignore_time_off = false): array
    {
        $segments = DaySegments::for(
            work_start_hour: $this->workStartHour(),
            work_end_hour: $this->workEndHour(),
            busy: $this->busyBands($user, $day),
            /**
             * Appointments stay put even here: a day off is something a person
             * can be asked to give up, an appointment with a customer is not.
             */
            blocked: $ignore_time_off ? [] : $this->blockedBands($user, $day),
        );

        return array_values(array_filter($segments, fn (array $segment) => $segment['kind'] === 'free'));
    }

    /**
     * Reads every appointment in the window for everybody at once, then keeps it
     * for the length of this request. Re-reading it per person per day is what
     * turns one question into hundreds of queries.
     *
     * @param  Collection<int, User>  $users
     */
    private function loadDiary(Collection $users, CarbonImmutable $from, int $days): void
    {
        $ids = $users->pluck('id')->sort()->values();
        $key = $from->toDateString() . '|' . $days . '|' . $ids->implode(',');

        if ($this->diary_key === $key) {
            return;
        }

        $events = Event::query()
            ->whereHas('executingUsers', fn ($q) => $q->whereIn('users.id', $ids))
            ->where('start', '<', Clock::startOfLocalDay($from->addDays($days)))
            ->where('end', '>', Clock::startOfLocalDay($from))
            ->with(['executingUsers' => fn ($q) => $q->whereIn('users.id', $ids)])
            ->get();

        $this->diary = [];

        foreach ($events as $event) {
            foreach ($event->executingUsers as $executor) {
                $this->diary[$executor->id] ??= collect();
                $this->diary[$executor->id]->push($event);
            }
        }

        $this->diary_key = $key;
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
        $start = $day->setTimezone(Clock::zone())->startOfDay();
        $end = $start->addDay();

        return ($this->diary[$user->id] ?? collect())
            ->filter(function (Event $event) use ($start, $end) {
                return Clock::toLocal($event->start) < $end
                    && Clock::toLocal($event->end) > $start;
            })
            ->map(function (Event $event) use ($user, $start) {
                /**
                 * Read on the clock somebody plans by. Stored moments are UTC and
                 * the hours around them — the working day, a papadag — are wall
                 * clock, so comparing the two directly puts every gap two hours out
                 * while looking entirely reasonable.
                 */
                $event_start = Clock::toLocal($event->start);
                $event_end = Clock::toLocal($event->end);

                /**
                 * Diverging times are clock times on one day, so they cannot
                 * describe a span. An appointment crossing midnight is clamped to
                 * the day instead, which is what the planner does with it too.
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
            })
            ->values()
            ->all();
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
