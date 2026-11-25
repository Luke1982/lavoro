<?php

namespace App\Actions\Appointments;

use App\Domain\Planning\Clock;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives an appointment a new start and end, for everybody on it.
 *
 * The one place a change of times alone is made: the planner's drag and
 * stretch arrive here through EventApiController, the assistant's
 * "verplaatsen" calls it directly, and a time changed in somebody's Google
 * calendar comes in through IncomingChangeHandler. A change that keeps the
 * length is a move, and on a move whoever has diverging times shifts along by
 * the same amount; a stretch leaves those alone, because only the appointment
 * got longer.
 *
 * Only the appointment changes. The crew of its werkbonnen and their jobs is
 * nobody's business here, so the diverging times are written on the
 * appointment's own crew and nowhere else. Times and crew are written in one
 * transaction, so whatever waits for the commit -- the push to Google above
 * all -- sees the appointment with both.
 */
class MoveAppointmentAction
{
    /** 23:59, the latest a diverging time can say. */
    private const LAST_MINUTE = 1439;

    public function execute(Event $event, CarbonImmutable $starts_at, CarbonImmutable $ends_at): Event
    {
        return DB::transaction(function () use ($event, $starts_at, $ends_at) {
            $crew = $event->executingUsers()->get();
            $shifted = $this->shiftedDivergingTimes($event, $starts_at, $ends_at, $crew);

            $event->update([
                'start' => $starts_at->utc()->format('Y-m-d H:i:s'),
                'end' => $ends_at->utc()->format('Y-m-d H:i:s'),
            ]);

            if ($shifted !== []) {
                $event->syncExecutingUsers(
                    $crew->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    diverging_times: array_map(
                        fn (array $times) => ['has_diverging_times' => true, ...$times],
                        $shifted,
                    ),
                );
            }

            return $event;
        });
    }

    /**
     * Whose diverging times this change moves, from what to what, by user id.
     * Empty for a stretch, and for a move by whole days, which keeps the wall
     * clock.
     *
     * @return array<int, array{name: string, from: string, to: string}>
     */
    public function describeDivergingShift(Event $event, CarbonImmutable $starts_at, CarbonImmutable $ends_at): array
    {
        $crew = $event->executingUsers()->get()->keyBy('id');

        return collect($this->shiftedDivergingTimes($event, $starts_at, $ends_at, $crew))
            ->map(fn (array $times, int $user_id) => [
                'name' => (string) $crew[$user_id]->name,
                'from' => substr($crew[$user_id]->pivot->diverging_start, 0, 5)
                    . '–' . substr($crew[$user_id]->pivot->diverging_end, 0, 5),
                'to' => $times['diverging_start'] . '–' . $times['diverging_end'],
            ])
            ->sortBy('name')
            ->all();
    }

    /**
     * Each diverging time after this change, by user id: the shifted times for
     * whoever has them on a move, the stored ones on anything else. What the
     * crew will actually be working, for whoever asks whether they are free.
     *
     * @return array<int, array{diverging_start: string, diverging_end: string}>
     */
    public function divergingTimesAfter(Event $event, CarbonImmutable $starts_at, CarbonImmutable $ends_at): array
    {
        $crew = $event->executingUsers()->get();

        return $this->shiftedDivergingTimes($event, $starts_at, $ends_at, $crew)
            + self::storedDivergingTimes($crew);
    }

    /**
     * The diverging times as the crew has them now, by user id.
     *
     * @param  Collection<int, User>  $crew  Executing users, loaded with their pivot.
     * @return array<int, array{diverging_start: string, diverging_end: string}>
     */
    public static function storedDivergingTimes(Collection $crew): array
    {
        return self::withDivergingTimes($crew)
            ->mapWithKeys(fn (User $user) => [(int) $user->id => [
                'diverging_start' => substr($user->pivot->diverging_start, 0, 5),
                'diverging_end' => substr($user->pivot->diverging_end, 0, 5),
            ]])
            ->all();
    }

    /**
     * The diverging times moved, by user id, or nothing when the crew keeps
     * theirs. Counted on the wall clock, so a move across a clock change keeps
     * 10:00 at 10:00 instead of turning it into 09:00. A move that would carry
     * someone's times past midnight stops them at the edge of the day with
     * their length intact, so the end never comes before the start; the
     * planner's optimistic shift does the same.
     *
     * @param  Collection<int, User>  $crew
     * @return array<int, array{diverging_start: string, diverging_end: string}>
     */
    private function shiftedDivergingTimes(
        Event $event,
        CarbonImmutable $starts_at,
        CarbonImmutable $ends_at,
        Collection $crew,
    ): array {
        if (!$this->isMove($event, $starts_at, $ends_at)) {
            return [];
        }

        $delta = Clock::minutesIntoLocalDay($starts_at) - Clock::minutesIntoLocalDay($event->start);

        if ($delta === 0) {
            return [];
        }

        return self::withDivergingTimes($crew)
            ->mapWithKeys(fn (User $user) => [(int) $user->id => $this->shift(
                $user->pivot->diverging_start,
                $user->pivot->diverging_end,
                $delta,
            )])
            ->all();
    }

    /**
     * @param  Collection<int, User>  $crew
     * @return Collection<int, User>
     */
    private static function withDivergingTimes(Collection $crew): Collection
    {
        return $crew->filter(fn (User $user) => $user->pivot->has_diverging_times
            && $user->pivot->diverging_start
            && $user->pivot->diverging_end);
    }

    private function isMove(Event $event, CarbonImmutable $starts_at, CarbonImmutable $ends_at): bool
    {
        $start_delta = $starts_at->getTimestamp() - $event->start->getTimestamp();
        $end_delta = $ends_at->getTimestamp() - $event->end->getTimestamp();

        return $start_delta !== 0 && $start_delta === $end_delta;
    }

    /**
     * @return array{diverging_start: string, diverging_end: string}
     */
    private function shift(string $start, string $end, int $minutes): array
    {
        $start_minutes = self::minutesOf($start);
        $end_minutes = self::minutesOf($end);
        $minutes = max(-$start_minutes, min($minutes, self::LAST_MINUTE - $end_minutes));

        return [
            'diverging_start' => self::clockTime($start_minutes + $minutes),
            'diverging_end' => self::clockTime($end_minutes + $minutes),
        ];
    }

    private static function minutesOf(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hours * 60 + $minutes;
    }

    private static function clockTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
