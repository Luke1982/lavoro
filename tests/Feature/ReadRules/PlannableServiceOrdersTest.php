<?php

namespace Tests\Feature\ReadRules;

use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The planner offers the werkbonnen this person may see (ServiceOrder::visibleTo):
 * every one with serviceorder.read, otherwise their own, also through an
 * appointment. There is no planner rule of its own.
 */
class PlannableServiceOrdersTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    /**
     * The picker only offers werkbonnen without an appointment yet.
     *
     * @return array{0: ServiceOrder, 1: ServiceOrder} on the werkbon, not on it
     */
    private function orders(User $user): array
    {
        $on_order = $this->order();
        $on_order->executingUsers()->attach($user->id, ['type' => 'executing']);

        return [$on_order, $this->order()];
    }

    private function offered(User $user, ServiceOrder $order): array
    {
        return $this->actingAs($user)
            ->getJson('/combo/serviceorders?customer_id=' . $order->customer_id)
            ->assertOk()
            ->json('*.id');
    }

    public function test_with_read_the_picker_offers_every_werkbon(): void
    {
        $user = $this->userWithPermissions('event.read', 'serviceorder.read');

        foreach ($this->orders($user) as $order) {
            $this->assertSame([$order->id], $this->offered($user, $order));
        }
    }

    public function test_without_read_the_picker_offers_the_werkbonnen_one_may_see(): void
    {
        $user = $this->userWithPermissions('event.read', 'serviceorder.read_own');
        [$on_order, $neither] = $this->orders($user);

        $this->assertSame([$on_order->id], $this->offered($user, $on_order));
        $this->assertSame([], $this->offered($user, $neither));
    }
}
