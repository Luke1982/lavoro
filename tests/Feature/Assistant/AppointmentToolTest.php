<?php

namespace Tests\Feature\Assistant;

use App\Domain\Planning\Clock;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Reading the diary.
 *
 * For the longest time nothing could, and it hid well because so much nearly
 * did: availability reports the gaps, the history says "Ingepland" without a
 * date. A werkbon knew it was planned and not when.
 */
class AppointmentToolTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private ?EventType $type = null;

    private function appointment(CarbonImmutable $day, ?ServiceOrder $order = null, ?User $mechanic = null): Event
    {
        $this->type ??= EventType::factory()->create();

        $event = Event::create([
            'event_type_id' => $this->type->id,
            'name' => 'Airco installeren',
            'status' => 'Gepland',
            'no_service_order' => $order === null,
            /**
             * Built the way the application stores them: the wall-clock time
             * somebody plans by, converted to the UTC it is kept in. Written as a
             * plain nine o'clock the row would mean eleven on the planner, and the
             * test would be asserting a time nobody would ever see.
             */
            'start' => Clock::fromLocal($day->format('Y-m-d') . ' 09:00'),
            'end' => Clock::fromLocal($day->format('Y-m-d') . ' 17:00'),
        ]);

        if ($order) {
            $order->events()->attach($event->id);
        }

        if ($mechanic) {
            $event->syncExecutingUsers([$mechanic->id]);
        }

        return $event;
    }

    private function ask(array $arguments, ?User $user = null): ToolResult
    {
        return app(ToolExecutor::class)->run(
            new ToolCall('find_appointments', $arguments, $user ?? $this->userWith('event.see_all'))
        );
    }

    public function test_it_says_when_a_werkbon_is_planned_and_with_whom(): void
    {
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $mechanic = User::factory()->create(['plannable' => true, 'name' => 'Alptug']);
        $this->appointment(CarbonImmutable::tomorrow(), $order, $mechanic);

        $rows = $this->ask(['service_order_id' => $order->id])->content['appointments'];

        $this->assertCount(1, $rows);
        $this->assertSame(CarbonImmutable::tomorrow()->toDateString(), $rows[0]['date']);
        $this->assertSame('09:00', $rows[0]['from']);
        $this->assertSame(['Alptug'], $rows[0]['mechanics']);
    }

    /**
     * "Wanneer gaan we dit doen" is about what is coming. A diary answered from
     * the beginning of time buries it.
     */
    public function test_it_looks_ahead_unless_asked_to_look_back(): void
    {
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->appointment(CarbonImmutable::today()->subMonth(), $order);

        $this->assertCount(0, $this->ask(['service_order_id' => $order->id])->content['appointments']);
        $this->assertCount(1, $this->ask(['service_order_id' => $order->id, 'include_past' => true])->content['appointments']);
    }

    /**
     * Right shape, not a real day. Left to the parser this came back as a PHP
     * error with a character position in it, which reads as though the diary were
     * broken rather than the date.
     */
    public function test_a_date_that_is_not_a_date_says_so_plainly(): void
    {
        foreach (['2026-13-45', '2026-02-30', 'morgen'] as $nonsense) {
            $result = $this->ask(['from' => $nonsense]);

            $this->assertTrue($result->is_error, $nonsense . ' was accepted');
            $this->assertStringContainsString('JJJJ-MM-DD', $result->content);
            $this->assertStringNotContainsString('parse', $result->content);
        }
    }

    /**
     * Handed names instead of numbers this filtered on nothing and returned the
     * whole diary — an answer about everybody to a question about two people, with
     * no sign anything had gone wrong.
     */
    public function test_names_where_numbers_belong_are_refused_rather_than_ignored(): void
    {
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->appointment(CarbonImmutable::tomorrow(), $order);

        $result = $this->ask(['user_ids' => 'Jeremy']);

        $this->assertTrue($result->is_error, 'the whole diary came back instead');
        $this->assertStringContainsString('nummers', $result->content);
    }

    public function test_a_mechanic_only_sees_the_appointments_they_are_on(): void
    {
        $mine = User::factory()->create(['plannable' => true]);
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $this->appointment(CarbonImmutable::tomorrow(), $order, $mine);
        $this->appointment(CarbonImmutable::tomorrow(), $order, User::factory()->create(['plannable' => true]));

        $rows = app(ToolExecutor::class)->run(new ToolCall('find_appointments', [], $mine))->content['appointments'];

        $this->assertCount(1, $rows);
        $this->assertSame([$mine->name], $rows[0]['mechanics']);
    }
}
