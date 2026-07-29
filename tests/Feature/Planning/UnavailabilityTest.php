<?php

namespace Tests\Feature\Planning;

use App\Domain\Planning\TechnicianAvailability;
use App\Models\User;
use App\Models\UserUnavailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Turning what someone recorded as unavailable into hours they cannot be booked.
 *
 * The shared fixture proves what DaySegments does once it has been handed a list
 * of blocked bands. This covers the step before that — reading the rows out of
 * the database and working out whether one applies to a given day — which is
 * where a papadag either lands on the right weekday or does not.
 *
 * Getting it wrong is quiet in the worst way. Nobody notices a mechanic who was
 * offered on their day off until they are already standing at a customer.
 */
class UnavailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function mechanic(): User
    {
        return User::factory()->create(['plannable' => true]);
    }

    private function availability(): TechnicianAvailability
    {
        return app(TechnicianAvailability::class);
    }

    /** @return array<int, string> "07:00-18:00" per free stretch. */
    private function freeOn(User $user, CarbonImmutable $day): array
    {
        return array_map(
            fn (array $segment) => sprintf(
                '%02d:%02d-%02d:%02d',
                intdiv($segment['start_min'], 60), $segment['start_min'] % 60,
                intdiv($segment['end_min'], 60), $segment['end_min'] % 60,
            ),
            $this->availability()->freeSegments($user->fresh()->load('unavailabilities'), $day),
        );
    }

    /**
     * The column counts Monday as 0 and Carbon counts Sunday as 0, so this is an
     * off-by-one waiting to happen — and it would land the block on the wrong day
     * of the week without anything looking broken.
     */
    public function test_a_weekly_block_lands_on_the_weekday_it_was_recorded_for(): void
    {
        $user = $this->mechanic();

        /** Thursday, in the Monday-is-nought counting the column uses. */
        UserUnavailability::create([
            'user_id' => $user->id,
            'type' => 'recurring',
            'repeat' => 'weekly',
            'day_of_week' => 3,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'label' => 'Papadag',
        ]);

        $thursday = CarbonImmutable::parse('next thursday')->startOfDay();

        $this->assertSame(['07:00-08:00'], $this->freeOn($user, $thursday));
        $this->assertSame(['07:00-18:00'], $this->freeOn($user, $thursday->addDay()));
        $this->assertSame(['07:00-18:00'], $this->freeOn($user, $thursday->subDay()));
    }

    public function test_a_weekly_block_comes_back_the_week_after(): void
    {
        $user = $this->mechanic();

        UserUnavailability::create([
            'user_id' => $user->id,
            'type' => 'recurring',
            'repeat' => 'weekly',
            'day_of_week' => 3,
            'start_time' => '08:00',
            'end_time' => '18:00',
        ]);

        $thursday = CarbonImmutable::parse('next thursday')->startOfDay();

        $this->assertSame(['07:00-08:00'], $this->freeOn($user, $thursday->addWeek()));
    }

    public function test_a_block_without_times_takes_the_whole_day(): void
    {
        $user = $this->mechanic();

        UserUnavailability::create([
            'user_id' => $user->id,
            'type' => 'recurring',
            'repeat' => 'weekly',
            'day_of_week' => 3,
            'start_time' => null,
            'end_time' => null,
        ]);

        $this->assertSame([], $this->freeOn($user, CarbonImmutable::parse('next thursday')->startOfDay()));
    }

    public function test_a_holiday_covers_every_day_of_the_range_including_the_last(): void
    {
        $user = $this->mechanic();
        $from = CarbonImmutable::today()->addWeek();

        UserUnavailability::create([
            'user_id' => $user->id,
            'type' => 'holiday',
            'date' => $from->toDateString(),
            'end_date' => $from->addDays(4)->toDateString(),
            'label' => 'Vakantie',
        ]);

        $this->assertSame(['07:00-18:00'], $this->freeOn($user, $from->subDay()));
        $this->assertSame([], $this->freeOn($user, $from));
        $this->assertSame([], $this->freeOn($user, $from->addDays(2)));
        $this->assertSame([], $this->freeOn($user, $from->addDays(4)), 'the last day of a holiday is still a holiday');
        $this->assertSame(['07:00-18:00'], $this->freeOn($user, $from->addDays(5)));
    }

    public function test_a_fortnightly_block_skips_the_week_in_between(): void
    {
        $user = $this->mechanic();
        $thursday = CarbonImmutable::parse('next thursday')->startOfDay();

        UserUnavailability::create([
            'user_id' => $user->id,
            'type' => 'recurring',
            'repeat' => 'biweekly',
            'reference_date' => $thursday->toDateString(),
            'day_of_week' => 3,
            'start_time' => '08:00',
            'end_time' => '18:00',
        ]);

        $this->assertSame(['07:00-08:00'], $this->freeOn($user, $thursday));
        $this->assertSame(['07:00-18:00'], $this->freeOn($user, $thursday->addWeek()));
        $this->assertSame(['07:00-08:00'], $this->freeOn($user, $thursday->addWeeks(2)));
    }

    /**
     * The whole point: a job that does not fit around someone's block has to move
     * to the next day it does fit, rather than being offered on the day off.
     */
    public function test_a_job_that_does_not_fit_around_a_block_moves_to_the_next_day(): void
    {
        $user = $this->mechanic();
        $thursday = CarbonImmutable::parse('next thursday')->startOfDay();

        UserUnavailability::create([
            'user_id' => $user->id,
            'type' => 'recurring',
            'repeat' => 'weekly',
            'day_of_week' => 3,
            'start_time' => '08:00',
            'end_time' => '18:00',
        ]);

        $slot = $this->availability()->firstSlot($user->fresh()->load('unavailabilities'), $thursday, 120, 7);

        $this->assertSame($thursday->addDay()->toDateString(), $slot['date']);
    }

    public function test_a_job_that_does_fit_in_the_gap_stays_on_the_day(): void
    {
        $user = $this->mechanic();
        $thursday = CarbonImmutable::parse('next thursday')->startOfDay();

        UserUnavailability::create([
            'user_id' => $user->id,
            'type' => 'recurring',
            'repeat' => 'weekly',
            'day_of_week' => 3,
            'start_time' => '08:00',
            'end_time' => '18:00',
        ]);

        $slot = $this->availability()->firstSlot($user->fresh()->load('unavailabilities'), $thursday, 45, 7);

        $this->assertSame($thursday->toDateString(), $slot['date']);
        $this->assertSame(7 * 60, $slot['start_min']);
    }
}
