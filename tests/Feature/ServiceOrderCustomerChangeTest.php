<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MaintenanceContract;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderCustomerChangeTest extends TestCase
{
    use RefreshDatabase;

    private Customer $old_customer;

    private Customer $new_customer;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->old_customer = Customer::factory()->create();
        $this->new_customer = Customer::factory()->create();
        $this->product = Product::factory()->create();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::create(['name' => 'werkbon-manager']);
        $permission = Permission::firstOrCreate(
            ['name' => 'serviceorder.update'],
            ['label' => 'serviceorder.update']
        );
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $this->user = $user;
    }

    private function asset(Customer $customer, array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
        ], $attributes));
    }

    private function orderWithJobOn(Asset $asset): ServiceOrder
    {
        $order = ServiceOrder::factory()->create(['customer_id' => $this->old_customer->id]);
        ServiceJob::factory()->create([
            'asset_id' => $asset->id,
            'service_order_id' => $order->id,
        ]);

        return $order;
    }

    public function test_changing_the_customer_without_a_strategy_is_rejected(): void
    {
        $asset = $this->asset($this->old_customer);
        $order = $this->orderWithJobOn($asset);

        $response = $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->new_customer->id]
        );

        $response->assertSessionHasErrors('asset_strategy');
        $this->assertSame($this->old_customer->id, $order->refresh()->customer_id);
        $this->assertSame($this->old_customer->id, $asset->refresh()->customer_id);
    }

    public function test_transferring_moves_the_machines_behind_the_jobs(): void
    {
        $asset = $this->asset($this->old_customer);
        $order = $this->orderWithJobOn($asset);

        $response = $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'transfer']
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame($this->new_customer->id, $order->refresh()->customer_id);
        $this->assertSame($this->new_customer->id, $asset->refresh()->customer_id);
    }

    public function test_the_jobs_stay_on_their_machines(): void
    {
        $asset = $this->asset($this->old_customer);
        $order = $this->orderWithJobOn($asset);
        $job_id = $order->serviceJobs()->first()->id;

        $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'transfer']
        );

        $this->assertDatabaseHas('service_jobs', ['id' => $job_id, 'asset_id' => $asset->id]);
    }

    public function test_the_contract_link_is_cleared_when_the_customer_changes(): void
    {
        $asset = $this->asset($this->old_customer);
        $order = $this->orderWithJobOn($asset);
        $contract = MaintenanceContract::factory()->create(['customer_id' => $this->old_customer->id]);
        $order->update(['maintenance_contract_id' => $contract->id]);

        $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'transfer']
        );

        $this->assertNull($order->refresh()->maintenance_contract_id);
    }

    public function test_clearing_the_contract_link_is_logged(): void
    {
        $asset = $this->asset($this->old_customer);
        $order = $this->orderWithJobOn($asset);
        $contract = MaintenanceContract::factory()->create(['customer_id' => $this->old_customer->id]);
        $order->update(['maintenance_contract_id' => $contract->id]);

        $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'transfer']
        );

        $this->assertTrue(
            $order->activities()->where('description', 'like', '%contract%')->exists()
        );
    }

    public function test_an_order_without_jobs_needs_no_strategy(): void
    {
        $order = ServiceOrder::factory()->create(['customer_id' => $this->old_customer->id]);

        $response = $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->new_customer->id]
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame($this->new_customer->id, $order->refresh()->customer_id);
    }

    public function test_jobs_already_on_the_new_customers_machines_need_no_strategy(): void
    {
        $asset = $this->asset($this->new_customer);
        $order = $this->orderWithJobOn($asset);

        $response = $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->new_customer->id]
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame($this->new_customer->id, $order->refresh()->customer_id);
    }

    public function test_a_job_on_a_child_machine_still_asks_for_a_strategy(): void
    {
        $root = $this->asset($this->old_customer);
        $child = Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $root->id,
            'product_id' => $this->product->id,
        ]);
        $order = $this->orderWithJobOn($child);

        $response = $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->new_customer->id]
        );

        $response->assertSessionHasErrors('asset_strategy');
    }

    public function test_transferring_from_a_job_on_a_child_moves_the_whole_machine(): void
    {
        $root = $this->asset($this->old_customer);
        $child = Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $root->id,
            'product_id' => $this->product->id,
        ]);
        $order = $this->orderWithJobOn($child);

        $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'transfer']
        );

        $this->assertSame($this->new_customer->id, $root->refresh()->customer_id);
        $this->assertNull($child->refresh()->customer_id);
        $this->assertSame($this->new_customer->id, $child->resolvedCustomerId());
    }

    public function test_the_location_map_is_applied(): void
    {
        $old_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        $new_location = Location::factory()->create(['customer_id' => $this->new_customer->id]);
        $asset = $this->asset($this->old_customer, ['location_id' => $old_location->id]);
        $order = $this->orderWithJobOn($asset);

        $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            [
                'customer_id' => $this->new_customer->id,
                'asset_strategy' => 'transfer',
                'location_map' => [$old_location->id => $new_location->id],
            ]
        );

        $this->assertSame($new_location->id, $asset->refresh()->location_id);
    }

    public function test_saving_an_unrelated_field_never_asks_for_a_strategy(): void
    {
        $asset = $this->asset($this->old_customer);
        $order = $this->orderWithJobOn($asset);

        $response = $this->actingAs($this->user)->patch(
            route('serviceorders.update', $order),
            ['customer_id' => $this->old_customer->id, 'description' => 'Aangepast']
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('Aangepast', $order->refresh()->description);
    }
}
