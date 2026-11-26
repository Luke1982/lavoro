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
 * A keuring makes its machine readable to whoever is planned for the werkbon:
 * they are there to inspect it. So a keuring may only be put on a werkbon for a
 * machine of that werkbon's customer, the rule the bakje applies
 * (ServiceOrder::isForTheCustomerOf), and only by someone who may open that
 * machine.
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

    public function test_adding_keuringen_on_the_werkbon_takes_the_right_to_read_machines(): void
    {
        $this->assertFalse($this->userWithPermissions('serviceorder.read', 'servicejob.create')->can('addServiceJobs', $this->order));
        $this->assertTrue($this->userWithPermissions('serviceorder.read', 'servicejob.create', 'asset.read')->can('addServiceJobs', $this->order));
    }

    public function test_whoever_is_planned_for_the_werkbon_reads_its_keuringen_and_their_machines(): void
    {
        $planned = $this->userWithPermissions();
        $elsewhere = $this->userWithPermissions('serviceorder.read');
        $machine = $this->machineOf($this->order->customer_id);
        $keuring = ServiceJob::factory()->create(['service_order_id' => $this->order->id, 'asset_id' => $machine->id]);
        $this->order->addExecutingUser($planned->id);

        $this->assertTrue($planned->can('view', $keuring));
        $this->assertTrue($planned->can('view', $machine));
        $this->assertTrue($planned->can('readServiceJobs', $this->order));
        $this->assertFalse($planned->can('view', $this->machineOf($this->order->customer_id)));
        $this->assertFalse($elsewhere->can('view', $keuring));
        $this->assertFalse($elsewhere->can('view', $machine));
        $this->assertFalse($elsewhere->can('readServiceJobs', $this->order));
    }

    public function test_a_machine_of_the_werkbon_customer_gets_its_keuring(): void
    {
        $planner = $this->userWithPermissions('serviceorder.read', 'servicejob.create', 'asset.read');
        $machine = $this->machineOf($this->order->customer_id);

        $this->addKeuring($planner, $machine)->assertSessionHasNoErrors();

        $this->assertTrue(ServiceJob::where('asset_id', $machine->id)->where('service_order_id', $this->order->id)->exists());
    }
}
