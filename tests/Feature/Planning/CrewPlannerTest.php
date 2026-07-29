<?php

namespace Tests\Feature\Planning;

use App\Domain\Planning\CrewPlanner;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use App\Models\UserUnavailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Staffing a job with more than one person.
 *
 * The whole point is simultaneity. Three mechanics each free on a different
 * afternoon are three free afternoons, not a crew, and a plan built on that
 * looks perfectly reasonable right up until three vans are meant to arrive at
 * the same address and one of them is somewhere else.
 */
class CrewPlannerTest extends TestCase
{
    use RefreshDatabase;

    private const WORK_DAY = 660;

    private ?EventType $event_type = null;

    /** One kind of appointment for the whole test; the factory's names run out otherwise. */
    private function eventType(): EventType
    {
        return $this->event_type ??= EventType::factory()->create();
    }

    private function planner(): CrewPlanner
    {
        return app(CrewPlanner::class);
    }

    /** @return Collection<int, User> */
    private function mechanics(int $count): Collection
    {
        User::factory()->count($count)->create(['plannable' => true]);

        return User::query()->where('plannable', true)->with(['planGroups', 'unavailabilities'])->orderBy('name')->get();
    }

    private function booked(User $user, CarbonImmutable $day, int $from_hour, int $until_hour): void
    {
        $event = Event::create([
            'event_type_id' => $this->eventType()->id,
            'status' => 'Gepland',
            'no_service_order' => true,
            'start' => $day->setTime($from_hour, 0),
            'end' => $day->setTime($until_hour, 0),
        ]);

        $event->syncExecutingUsers([$user->id]);
    }

    private function tomorrow(): CarbonImmutable
    {
        return CarbonImmutable::tomorrow();
    }

    /**
     * The one that matters. Both are free for plenty of hours, never the same
     * ones, so there is no crew of two — however tempting the totals look.
     */
    public function test_two_people_free_at_different_times_are_not_a_crew(): void
    {
        $mechanics = $this->mechanics(2);
        $day = $this->tomorrow();

        /** Mornings for one, afternoons for the other, every day of the window. */
        foreach (range(0, 20) as $offset) {
            $on = $day->addDays($offset);
            $this->booked($mechanics[0], $on, 12, 18);
            $this->booked($mechanics[1], $on, 7, 13);
        }

        $option = $this->planner()->option($mechanics, $day, 240 * 2, crew_size: 2);

        $this->assertNull($option, 'two people who are never free together were planned as a crew');
    }

    public function test_two_people_free_at_the_same_time_are_a_crew(): void
    {
        $mechanics = $this->mechanics(2);

        $option = $this->planner()->option($mechanics, $this->tomorrow(), 240 * 2, crew_size: 2);

        $this->assertNotNull($option);
        $this->assertSame(2, $option['crew_size']);
        $this->assertCount(2, $option['crew']);
        $this->assertTrue($option['same_crew_throughout']);
    }

    /**
     * The window has to be inside everybody's free stretch for its whole length,
     * not merely touch it. An hour of overlap does not staff a four-hour job.
     */
    public function test_an_overlap_shorter_than_the_job_is_not_enough(): void
    {
        $mechanics = $this->mechanics(2);
        $day = $this->tomorrow();

        foreach (range(0, 20) as $offset) {
            $on = $day->addDays($offset);
            $this->booked($mechanics[0], $on, 12, 18);
            $this->booked($mechanics[1], $on, 7, 11);
        }

        /** They share 11:00-12:00. A two-hour job for two people does not fit. */
        $this->assertNull($this->planner()->option($mechanics, $day, 120 * 2, crew_size: 2));

        $option = $this->planner()->option($mechanics, $day, 60 * 2, crew_size: 2);
        $this->assertNotNull($option, 'an hour that does fit was rejected');
        $this->assertSame(11 * 60, $option['days'][0]['from']);
    }

    public function test_more_people_means_fewer_days(): void
    {
        $mechanics = $this->mechanics(4);
        $work = 3 * self::WORK_DAY;

        $this->assertSame(3, $this->planner()->option($mechanics, $this->tomorrow(), $work, 1)['days_needed']);
        $this->assertSame(2, $this->planner()->option($mechanics, $this->tomorrow(), $work, 2)['days_needed']);
        $this->assertSame(1, $this->planner()->option($mechanics, $this->tomorrow(), $work, 3)['days_needed']);
    }

    public function test_a_crew_bigger_than_the_workforce_is_not_offered(): void
    {
        $mechanics = $this->mechanics(2);

        $this->assertNull($this->planner()->option($mechanics, $this->tomorrow(), 480, crew_size: 5));
    }

    /**
     * A day somebody has off is not a day they can be put on a crew, however
     * empty the rest of their diary is.
     */
    public function test_a_day_off_keeps_someone_out_of_the_crew_that_day(): void
    {
        $mechanics = $this->mechanics(2);
        $thursday = CarbonImmutable::parse('next thursday')->startOfDay();

        UserUnavailability::create([
            'user_id' => $mechanics[1]->id,
            'type' => 'recurring',
            'repeat' => 'weekly',
            'day_of_week' => 3,
            'start_time' => null,
            'end_time' => null,
            'label' => 'Papadag',
        ]);

        $option = $this->planner()->option(
            $mechanics->fresh()->load('unavailabilities'),
            $thursday,
            self::WORK_DAY * 2,
            crew_size: 2,
        );

        $this->assertNotNull($option);
        $this->assertNotSame(
            $thursday->toDateString(),
            $option['days'][0]['date'],
            'a crew was formed on a day one of them had off',
        );
    }

    public function test_a_job_that_never_fits_in_the_window_reports_nothing(): void
    {
        $mechanics = $this->mechanics(2);
        $day = $this->tomorrow();

        foreach (range(0, 20) as $offset) {
            foreach ($mechanics as $mechanic) {
                $this->booked($mechanic, $day->addDays($offset), 7, 18);
            }
        }

        $this->assertNull($this->planner()->option($mechanics, $day, 480, crew_size: 1));
    }

    /**
     * Everything the planner reports has to be readable straight off as an
     * appointment, so the hours have to be real hours on the day named.
     */
    public function test_every_day_it_reports_is_long_enough_for_the_work(): void
    {
        $mechanics = $this->mechanics(3);
        $option = $this->planner()->option($mechanics, $this->tomorrow(), 3 * self::WORK_DAY, crew_size: 3);

        $this->assertNotNull($option);

        foreach ($option['days'] as $day) {
            $this->assertSame(
                $option['minutes_per_person_per_day'],
                $day['until'] - $day['from'],
                'a day was reported that is not as long as the work it carries',
            );
        }
    }
}
