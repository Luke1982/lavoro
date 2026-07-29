<?php

namespace App\Domain\Planning;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out the ways a job could be staffed: how many people, on which days.
 *
 * "Tien binnenunits, zeker drie dagen werk, of één dag met drie man" is a single
 * amount of work that can be spent several ways, and which of those is possible
 * depends on when people are free together. Availability per person cannot
 * answer it: three mechanics each free on a different afternoon are three free
 * afternoons, not a crew.
 *
 * So the unit here is man-minutes. A crew of three halves the days needed and
 * doubles what has to line up, and every option comes back with the dates and
 * the names rather than the arithmetic, because that is what gets planned.
 */
class CrewPlanner
{
    public function __construct(private readonly TechnicianAvailability $availability) {}

    /**
     * One way of staffing the job, or nothing if there is no room for it.
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, int>  $ranking  Lower sorts first; used to pick who from an over-full crew.
     * @return array{
     *     crew_size: int,
     *     days_needed: int,
     *     minutes_per_person_per_day: int,
     *     days: array<int, array{date: string, from: int, until: int, user_ids: array<int, int>}>,
     *     crew: array<int, int>,
     *     same_crew_throughout: bool
     * }|null
     */
    public function option(
        Collection $users,
        CarbonImmutable $from,
        int $total_minutes,
        int $crew_size,
        array $ranking = [],
        int $window_days = 21,
    ): ?array {
        return $this->optionFrom(
            $this->availability->segmentsFor($users, $from, $window_days),
            $users,
            $from,
            $total_minutes,
            $crew_size,
            $ranking,
            $window_days,
        );
    }

    /**
     * The same thing for a grid already in hand.
     *
     * Asking about four crew sizes is four passes over one fortnight, and the
     * fortnight does not change between them.
     *
     * @param  array<int, array<string, array<int, array{start_min: int, end_min: int}>>>  $grid
     * @param  Collection<int, User>  $users
     * @param  array<int, int>  $ranking
     * @return array{
     *     crew_size: int,
     *     days_needed: int,
     *     minutes_per_person_per_day: int,
     *     days: array<int, array{date: string, from: int, until: int, user_ids: array<int, int>}>,
     *     crew: array<int, int>,
     *     same_crew_throughout: bool
     * }|null
     */
    public function optionFrom(
        array $grid,
        Collection $users,
        CarbonImmutable $from,
        int $total_minutes,
        int $crew_size,
        array $ranking = [],
        int $window_days = 21,
    ): ?array {
        $work_day = ($this->availability->workEndHour() - $this->availability->workStartHour()) * 60;

        /**
         * A working day of no length would divide by nought below. It takes a
         * mangled setting to get here, and the answer to give is "no plan"
         * rather than a page of stack trace.
         */
        if ($work_day < 1 || $crew_size < 1 || $total_minutes < 1 || $crew_size > $users->count()) {
            return null;
        }

        $days_needed = (int) ceil($total_minutes / ($crew_size * $work_day));
        $per_day = (int) ceil($total_minutes / ($crew_size * $days_needed));

        $days = [];
        $sets = [];

        for ($offset = 0; $offset < $window_days && count($days) < $days_needed; $offset++) {
            $date = $from->addDays($offset)->toDateString();
            $window = $this->earliestWindowFor($grid, $users, $date, $per_day, $crew_size);

            if ($window === null) {
                continue;
            }

            $days[] = [
                'date' => $date,
                'from' => $window['start'],
                'until' => $window['start'] + $per_day,
                'user_ids' => $window['user_ids'],
            ];

            $sets[] = $window['user_ids'];
        }

        if (count($days) < $days_needed) {
            return null;
        }

        /**
         * The same faces every day where that is possible. A crew that changes
         * daily is a worse plan even when the hours add up, so it is only
         * reported when nothing else fits — and reported as such.
         */
        $throughout = array_values(array_intersect(...$sets));
        $same_crew = count($throughout) >= $crew_size;

        $crew = $this->pick($same_crew ? $throughout : $sets[0], $crew_size, $ranking);

        if ($same_crew) {
            $days = array_map(function (array $day) use ($crew) {
                $day['user_ids'] = $crew;

                return $day;
            }, $days);
        }

        return [
            'crew_size' => $crew_size,
            'days_needed' => $days_needed,
            'minutes_per_person_per_day' => $per_day,
            'days' => $days,
            'crew' => $crew,
            'same_crew_throughout' => $same_crew,
        ];
    }

    /**
     * The earliest moment on this day when enough people are free side by side
     * for long enough, and who they are.
     *
     * Only the starts of free stretches are tried. Any workable window can be
     * slid earlier until it meets one, and everybody free during it stays free —
     * so nothing is missed by not walking the day minute by minute.
     *
     * @param  array<int, array<string, array<int, array{start_min: int, end_min: int}>>>  $grid
     * @param  Collection<int, User>  $users
     * @return array{start: int, user_ids: array<int, int>}|null
     */
    private function earliestWindowFor(array $grid, Collection $users, string $date, int $duration, int $crew_size): ?array
    {
        $starts = [];

        foreach ($users as $user) {
            foreach ($grid[$user->id][$date] ?? [] as $segment) {
                $starts[] = $segment['start_min'];
            }
        }

        $starts = array_values(array_unique($starts));
        sort($starts);

        foreach ($starts as $start) {
            $free = [];

            foreach ($users as $user) {
                foreach ($grid[$user->id][$date] ?? [] as $segment) {
                    if ($segment['start_min'] <= $start && $segment['end_min'] >= $start + $duration) {
                        $free[] = $user->id;

                        break;
                    }
                }
            }

            if (count($free) >= $crew_size) {
                return ['start' => $start, 'user_ids' => $free];
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $available
     * @param  array<int, int>  $ranking
     * @return array<int, int>
     */
    private function pick(array $available, int $crew_size, array $ranking): array
    {
        usort($available, fn (int $a, int $b) => ($ranking[$a] ?? PHP_INT_MAX) <=> ($ranking[$b] ?? PHP_INT_MAX));

        return array_slice($available, 0, $crew_size);
    }
}
