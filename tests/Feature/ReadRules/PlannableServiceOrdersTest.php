<?php

namespace Tests\Feature\ReadRules;

use App\Models\Customer;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The werkbonnen the planner offers to plan: all of them with serviceorder.read,
 * otherwise only those this person is on as uitvoerder of the werkbon itself.
 * Narrower than ServiceOrder::visibleTo, which also counts the werkbon's
 * appointments; the planner's list and its picker were written that way and
 * ServiceOrder::scopePlannableFor keeps them so.
 */
class PlannableServiceOrdersTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    /** @return array{0: ServiceOrder, 1: ServiceOrder, 2: ServiceOrder} on the werkbon, on an appointment only, on neither */
    private function orders(User $user): array
    {
        $on_order = $this->order();
        $on_order->executingUsers()->attach($user->id, ['type' => 'executing']);

        $on_appointment = $this->order();
        $event = Event::factory()->create();
        $on_appointment->events()->attach($event->id);
        $event->executingUsers()->attach($user->id, ['type' => 'executing']);

        return [$on_order, $on_appointment, $this->order()];
    }

    public function test_without_read_only_the_werkbonnen_one_is_on_are_plannable(): void
    {
        $user = $this->userWithPermissions('event.read', 'serviceorder.read_own');
        [$on_order, $on_appointment, $neither] = $this->orders($user);

        $this->assertSame([$on_order->id], ServiceOrder::plannableFor($user)->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            [$on_order->id, $on_appointment->id],
            ServiceOrder::visibleTo($user)->orderBy('id')->pluck('id')->all(),
            'visibleTo also counts the appointments; the planner does not'
        );
        $this->assertNotContains($neither->id, ServiceOrder::visibleTo($user)->pluck('id')->all());
    }

    public function test_with_read_every_werkbon_is_plannable(): void
    {
        $user = $this->userWithPermissions('event.read', 'serviceorder.read');
        $ids = array_map(fn (ServiceOrder $order) => $order->id, $this->orders($user));

        $this->assertSame($ids, ServiceOrder::plannableFor($user)->whereKey($ids)->orderBy('id')->pluck('id')->all());
    }

    public function test_the_planner_picker_offers_the_same_werkbonnen(): void
    {
        $user = $this->userWithPermissions('event.read', 'serviceorder.read_own');
        [$on_order, $on_appointment] = $this->orders($user);

        foreach ([$on_order, $on_appointment] as $order) {
            $offered = $this->actingAs($user)
                ->getJson('/combo/serviceorders?customer_id=' . $order->customer_id)
                ->assertOk()
                ->json('*.id');

            $this->assertSame($order->is($on_order) ? [$order->id] : [], $offered);
        }
    }
}
