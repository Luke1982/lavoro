<?php

namespace Tests\Feature\Assistant;

use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\CreateEventTool;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Location;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\UserUnavailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The first tool that changes anything.
 *
 * Everything here is about the gap between what somebody read and what actually
 * happens. A wrong appointment is not a visible failure — it is a van at the
 * wrong address on Tuesday — so the interesting cases are the ones where the
 * result still looks fine.
 */
class CreateEventToolTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function mechanic(string $name = 'Jeremy'): User
    {
        return User::factory()->create(['plannable' => true, 'name' => $name]);
    }

    private function tomorrowAt(int $hour): string
    {
        return CarbonImmutable::tomorrow()->setTime($hour, 0)->format('Y-m-d H:i');
    }

    private function propose(array $arguments, ?User $user = null): ToolResult
    {
        EventType::factory()->create();

        return app(ToolExecutor::class)->run(
            new ToolCall(CreateEventTool::name(), $arguments, $user ?? $this->admin())
        );
    }

    private function carryOut(array $arguments, ?User $user = null): ToolResult
    {
        EventType::factory()->create();
        $user ??= $this->admin();

        return app(ToolExecutor::class)->run(new ToolCall(
            CreateEventTool::name(),
            $arguments,
            $user,
            confirmation_token: ConfirmationToken::for(CreateEventTool::name(), $arguments, $user)->encoded(),
        ));
    }

    public function test_it_asks_before_it_writes(): void
    {
        $mechanic = $this->mechanic();

        $result = $this->propose([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
        ]);

        $this->assertSame('bevestiging_nodig', $result->content['status']);
        $this->assertSame(0, Event::count(), 'an appointment was made before anybody agreed to it');
    }

    public function test_a_confirmed_appointment_lands_in_the_diary_with_its_mechanic(): void
    {
        $mechanic = $this->mechanic();

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
            'subject' => 'Airco plaatsen',
        ]);

        $this->assertFalse($result->is_error, is_string($result->content) ? $result->content : '');

        $event = Event::sole();

        /** Named subject to the model, stored in the column the rest of the app reads. */
        $this->assertSame('Airco plaatsen', $event->name);
        $this->assertSame([$mechanic->id], $event->executingUsers->pluck('id')->all());
    }

    /**
     * It goes through the same action the planner screen uses, so the timeline
     * entry and everything else hanging off that happens because of the action
     * rather than because this tool remembered to.
     */
    public function test_it_leaves_the_same_trail_as_the_planner_screen(): void
    {
        $mechanic = $this->mechanic();

        $this->carryOut([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
        ]);

        $this->assertGreaterThan(0, Event::sole()->activities()->count(), 'nothing was written to the timeline');
    }

    public function test_it_refuses_a_mechanic_who_is_already_booked(): void
    {
        $mechanic = $this->mechanic();

        $this->carryOut([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(12),
            'user_ids' => [$mechanic->id],
        ]);

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(10),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
        ]);

        $this->assertTrue($result->is_error, 'somebody was double-booked');
        $this->assertSame(1, Event::count());
    }

    /**
     * The diary is checked when the appointment is made, not when availability
     * was read. Minutes pass while somebody thinks about it, and the slot can go.
     */
    public function test_it_refuses_a_day_somebody_has_off(): void
    {
        $mechanic = $this->mechanic();
        $thursday = CarbonImmutable::parse('next thursday');

        UserUnavailability::create([
            'user_id' => $mechanic->id,
            'type' => 'recurring',
            'repeat' => 'weekly',
            'day_of_week' => 3,
            'start_time' => null,
            'end_time' => null,
            'label' => 'Papadag',
        ]);

        $result = $this->carryOut([
            'starts_at' => $thursday->setTime(9, 0)->format('Y-m-d H:i'),
            'ends_at' => $thursday->setTime(11, 0)->format('Y-m-d H:i'),
            'user_ids' => [$mechanic->id],
        ]);

        $this->assertTrue($result->is_error, 'an appointment was booked straight over a day off');
        $this->assertSame(0, Event::count());
    }

    public function test_it_refuses_the_past(): void
    {
        $mechanic = $this->mechanic();
        $result = $this->carryOut([
            'starts_at' => CarbonImmutable::yesterday()->setTime(9, 0)->format('Y-m-d H:i'),
            'ends_at' => CarbonImmutable::yesterday()->setTime(11, 0)->format('Y-m-d H:i'),
            'user_ids' => [$mechanic->id],
        ]);

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Event::count());
    }

    public function test_it_refuses_an_appointment_that_ends_before_it_starts(): void
    {
        $mechanic = $this->mechanic();
        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(14),
            'ends_at' => $this->tomorrowAt(9),
            'user_ids' => [$mechanic->id],
        ]);

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Event::count());
    }

    public function test_it_refuses_somebody_who_is_not_plannable(): void
    {
        $desk = User::factory()->create(['plannable' => false]);

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$desk->id],
        ]);

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Event::count());
    }

    /**
     * Planning is somebody else's day, so the same permission the planner screen
     * asks for governs it here. Reading availability is not the same right as
     * filling it in.
     */
    public function test_somebody_who_may_not_plan_cannot_plan(): void
    {
        $mechanic = $this->mechanic();

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
        ], $this->userWith('serviceorder.read_own'));

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Event::count());
    }

    /**
     * Asked for "plan het in en maak er een bon voor", the two used to be made by
     * separate tools and separate confirmations — so they arrived unconnected: a
     * werkbon with no appointment on it, and an appointment belonging to nothing.
     */
    public function test_an_appointment_and_its_new_werkbon_arrive_attached(): void
    {
        $mechanic = $this->mechanic();
        $customer = Customer::factory()->create();

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(13),
            'ends_at' => $this->tomorrowAt(17),
            'user_ids' => [$mechanic->id],
            'subject' => 'Airco installatie',
            'create_service_order_for_customer_id' => $customer->id,
        ], $this->userWithPermissions('event.create', 'serviceorder.create'));

        $this->assertFalse($result->is_error, json_encode($result->content));

        $order = ServiceOrder::sole();
        $event = Event::sole();

        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('Airco installatie', $order->description);
        $this->assertSame([$event->id], $order->events->pluck('id')->all());
        $this->assertSame($order->id, $result->content['service_order_id']);
    }

    public function test_it_will_not_both_attach_and_create_a_werkbon(): void
    {
        $mechanic = $this->mechanic();
        $customer = Customer::factory()->create();
        $existing = ServiceOrder::factory()->create(['customer_id' => $customer->id]);

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(13),
            'ends_at' => $this->tomorrowAt(17),
            'user_ids' => [$mechanic->id],
            'service_order_id' => $existing->id,
            'create_service_order_for_customer_id' => $customer->id,
        ], $this->userWithPermissions('event.create', 'serviceorder.create'));

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Event::count());
    }

    /**
     * Filling in a diary and opening a werkbon are separate rights, and this does
     * both, so holding one of them is not enough.
     */
    public function test_making_the_werkbon_needs_the_werkbon_permission_too(): void
    {
        $mechanic = $this->mechanic();
        $customer = Customer::factory()->create();

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(13),
            'ends_at' => $this->tomorrowAt(17),
            'user_ids' => [$mechanic->id],
            'create_service_order_for_customer_id' => $customer->id,
        ], $this->userWith('event.create'));

        $this->assertTrue($result->is_error);
        $this->assertSame(0, ServiceOrder::count());
        $this->assertSame(0, Event::count());
    }

    /**
     * A customer can have several sites, and the address on the customer is not
     * necessarily where the work happens. A real address at the wrong building is
     * the sort of mistake that surfaces when a van is already parked outside it.
     */
    public function test_an_appointment_can_be_put_at_one_of_the_customers_sites(): void
    {
        $mechanic = $this->mechanic();
        $customer = Customer::factory()->create();
        $site = Location::factory()->create(['customer_id' => $customer->id]);

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
            'create_service_order_for_customer_id' => $customer->id,
            'location_id' => $site->id,
        ], $this->userWithPermissions('event.create', 'serviceorder.create'));

        $this->assertFalse($result->is_error, json_encode($result->content));
        $this->assertSame($site->id, Event::sole()->location_id);
    }

    /**
     * The werkbon and its appointment are for the same site. Left off the werkbon,
     * it read as having no location while the appointment on it named one, and
     * whoever opened the werkbon saw the blank.
     */
    public function test_the_werkbon_gets_the_same_site_as_its_appointment(): void
    {
        $mechanic = $this->mechanic();
        $customer = Customer::factory()->create();
        $site = Location::factory()->create(['customer_id' => $customer->id]);

        $this->carryOut([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
            'create_service_order_for_customer_id' => $customer->id,
            'location_id' => $site->id,
        ], $this->userWithPermissions('event.create', 'serviceorder.create'));

        $this->assertSame($site->id, Event::sole()->location_id);
        $this->assertSame($site->id, ServiceOrder::sole()->location_id, 'the werkbon was left without a site');
    }

    public function test_a_site_belonging_to_another_customer_is_refused(): void
    {
        $mechanic = $this->mechanic();
        $customer = Customer::factory()->create();
        $elsewhere = Location::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
            'create_service_order_for_customer_id' => $customer->id,
            'location_id' => $elsewhere->id,
        ], $this->userWithPermissions('event.create', 'serviceorder.create'));

        $this->assertTrue($result->is_error, "another customer's site was used");
        $this->assertSame(0, Event::count());
    }

    /**
     * A site with no customer to check it against cannot be checked, so it is not
     * accepted either.
     */
    public function test_a_site_without_a_customer_or_werkbon_is_refused(): void
    {
        $mechanic = $this->mechanic();
        $site = Location::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $result = $this->carryOut([
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
            'location_id' => $site->id,
        ]);

        $this->assertTrue($result->is_error);
        $this->assertSame(0, Event::count());
    }

    /**
     * The endpoint that redeems an approval runs no model at all: what the person
     * read is carried out directly. Putting a language model between the words on
     * screen and the act would mean the two could differ.
     */
    public function test_the_confirm_endpoint_carries_out_what_was_agreed(): void
    {
        EventType::factory()->create();
        $mechanic = $this->mechanic();
        $user = $this->userWithPermissions('assistant.use', 'event.create');

        $arguments = [
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
        ];

        $this->actingAs($user)
            ->postJson('/assistant/confirm', [
                'token' => ConfirmationToken::for(CreateEventTool::name(), $arguments, $user)->encoded(),
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(1, Event::count());
    }

    public function test_the_confirm_endpoint_refuses_a_token_from_somebody_else(): void
    {
        EventType::factory()->create();
        $mechanic = $this->mechanic();
        $owner = $this->userWithPermissions('assistant.use', 'event.create');

        $token = ConfirmationToken::for(CreateEventTool::name(), [
            'starts_at' => $this->tomorrowAt(9),
            'ends_at' => $this->tomorrowAt(11),
            'user_ids' => [$mechanic->id],
        ], $owner)->encoded();

        $this->actingAs($this->userWithPermissions('assistant.use', 'event.create'))
            ->postJson('/assistant/confirm', ['token' => $token])
            ->assertStatus(422);

        $this->assertSame(0, Event::count());
    }

    public function test_the_confirm_endpoint_is_behind_the_same_permission(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/assistant/confirm', ['token' => 'x'])
            ->assertForbidden();
    }
}
