<?php

namespace Tests\Feature\ReadRules;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\User;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A keuring makes its machine relevant to whoever works the werkbon, and so
 * readable to them. It may therefore only be put on a werkbon for a machine of
 * that werkbon's customer, and one this person may open: the same rule the
 * bakje applies (ServiceOrder::isForTheCustomerOf).
 */
class ServiceJobMachineRuleTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private ServiceOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function machineOf(int $customer_id): Asset
    {
        return Asset::factory()->create(['customer_id' => $customer_id, 'product_id' => Product::factory()->create()->id]);
    }

    private function addKeuring(User $user, Asset $machine)
    {
        return $this->actingAs($user)->from('/serviceorders/' . $this->order->id)->post('/servicejobs', [
            'service_order_id' => $this->order->id,
            'asset_id' => $machine->id,
            'outcome' => 'Nog geen uitkomst',
        ]);
    }

    public function test_a_machine_of_another_customer_is_refused(): void
    {
        $planner = $this->userWithPermissions('serviceorder.read', 'servicejob.create', 'asset.read');
        $foreign = $this->machineOf(Customer::factory()->create()->id);

        $this->addKeuring($planner, $foreign)->assertSessionHasErrors('asset_id');

        $this->assertFalse(ServiceJob::where('asset_id', $foreign->id)->exists());
    }

    public function test_a_machine_this_person_may_not_open_is_refused(): void
    {
        $planner = $this->userWithPermissions('serviceorder.read', 'servicejob.create');
        $machine = $this->machineOf($this->order->customer_id);

        $this->addKeuring($planner, $machine)->assertSessionHasErrors('asset_id');

        $this->assertFalse(ServiceJob::where('asset_id', $machine->id)->exists());
    }

    public function test_a_machine_of_the_werkbon_customer_gets_its_keuring(): void
    {
        $planner = $this->userWithPermissions('serviceorder.read', 'servicejob.create', 'asset.read');
        $machine = $this->machineOf($this->order->customer_id);

        $this->addKeuring($planner, $machine)->assertSessionHasNoErrors();

        $this->assertTrue(ServiceJob::where('asset_id', $machine->id)->where('service_order_id', $this->order->id)->exists());
    }
}
