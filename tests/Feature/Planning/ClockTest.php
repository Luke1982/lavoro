<?php

namespace Tests\Feature\Planning;

use App\Domain\Planning\Clock;
use App\Domain\Planning\TechnicianAvailability;
use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Datetimes are stored in UTC and spoken in local time, and the assistant did
 * neither consistently.
 *
 * An appointment asked for at eight was written as eight UTC and appeared at ten,
 * which is what somebody noticed. Underneath that, worse: availability compared
 * appointments in UTC against working hours and days off recorded as plain wall
 * clock, so every free gap it worked out was two hours from the one on screen — and
 * a mechanic reported free at eight was genuinely busy then, with nothing in the
 * answer to say so.
 */
class ClockTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Amsterdam']);
    }

    public function test_a_time_somebody_says_is_stored_as_the_moment_it_means(): void
    {
        /** Summer, so Amsterdam is two hours ahead of UTC. */
        $this->assertSame('2026-07-31 06:00:00', Clock::fromLocal('2026-07-31 08:00')->format('Y-m-d H:i:s'));

        /** And winter, where it is one. */
        $this->assertSame('2026-01-31 07:00:00', Clock::fromLocal('2026-01-31 08:00')->format('Y-m-d H:i:s'));
    }

    public function test_a_stored_moment_is_read_back_on_the_clock_people_use(): void
    {
        $this->assertSame('08:00', Clock::toLocal('2026-07-31 06:00:00')->format('H:i'));
        $this->assertSame(480, Clock::minutesIntoLocalDay('2026-07-31 06:00:00'));
    }

    public function test_something_that_is_not_a_time_is_not_guessed_at(): void
    {
        $this->assertNull(Clock::fromLocal('morgen'));
        $this->assertNull(Clock::fromLocal('2026-07-31'));
        $this->assertNull(Clock::fromLocal(null));
    }

    /**
     * The one that hid. A mechanic booked 09:00–17:00 on the planner is free before
     * nine, and the answer used to be computed from the stored 07:00 instead.
     */
    public function test_a_booked_mechanic_is_busy_at_the_hours_the_planner_shows(): void
    {
        $mechanic = User::factory()->create(['plannable' => true]);
        $day = CarbonImmutable::parse('2026-07-31', 'Europe/Amsterdam');

        $event = Event::create([
            'event_type_id' => EventType::factory()->create()->id,
            'status' => 'Gepland',
            'no_service_order' => true,
            /** Stored UTC, which is 09:00 to 17:00 on the planner. */
            'start' => '2026-07-31 07:00:00',
            'end' => '2026-07-31 15:00:00',
        ]);
        $event->syncExecutingUsers([$mechanic->id]);

        $free = app(TechnicianAvailability::class)->freeSegments($mechanic->load('unavailabilities'), $day);

        $this->assertCount(2, $free, 'the busy band did not land where the planner shows it');
        $this->assertSame(7 * 60, $free[0]['start_min']);
        $this->assertSame(9 * 60, $free[0]['end_min'], 'free until nine, when the appointment starts');
        $this->assertSame(17 * 60, $free[1]['start_min'], 'free again from five, when it ends');
    }

    /**
     * The whole way round: asked for eight, stored as six, shown as eight, read
     * back as eight.
     */
    public function test_an_appointment_comes_back_at_the_time_it_was_asked_for(): void
    {
        EventType::firstOrCreate(['name' => 'Bezoek']);
        $mechanic = User::factory()->create(['plannable' => true]);
        $customer = Customer::factory()->create();
        $user = $this->userWithPermissions('event.create', 'serviceorder.create', 'event.see_all');

        $arguments = [
            'starts_at' => '2026-07-31 08:00',
            'ends_at' => '2026-07-31 12:00',
            'user_ids' => [$mechanic->id],
            'event_type' => 'Bezoek',
            'create_service_order_for_customer_id' => $customer->id,
        ];

        $made = app(ToolExecutor::class)->run(new ToolCall(
            'create_event',
            $arguments,
            $user,
            confirmation_token: ConfirmationToken::for('create_event', $arguments, $user)->encoded(),
        ));

        $this->assertFalse($made->is_error, json_encode($made->content));
        $this->assertSame('2026-07-31 06:00:00', Event::sole()->start->format('Y-m-d H:i:s'));

        $read = app(ToolExecutor::class)->run(new ToolCall(
            'find_appointments',
            ['from' => '2026-07-31', 'until' => '2026-07-31'],
            $user,
        ));

        $this->assertSame('08:00', $read->content['appointments'][0]['from']);
        $this->assertSame('12:00', $read->content['appointments'][0]['until']);
        $this->assertStringContainsString('08:00', $made->summary);
    }
}
