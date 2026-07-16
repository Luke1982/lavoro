<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MaintenanceContract;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\MaintenanceContractServiceOrderGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'admin'])->id);

        return $user;
    }

    private function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'role-' . $permission]);
        $perm = Permission::firstOrCreate(['name' => $permission], ['label' => $permission]);
        $role->permissions()->attach($perm->id);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_deleting_a_location_detaches_assets_and_service_orders(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $asset = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'location_id' => $location->id]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id, 'location_id' => $location->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$location->id}", ['disposition' => 'detach'])
            ->assertRedirect();

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
        $this->assertNull($asset->fresh()->location_id);
        $this->assertNull($order->fresh()->location_id);
    }

    public function test_deleting_a_location_moves_assets_and_service_orders_to_the_target(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $from = Location::factory()->create(['customer_id' => $customer->id]);
        $to = Location::factory()->create(['customer_id' => $customer->id]);
        $asset = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'location_id' => $from->id]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id, 'location_id' => $from->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$from->id}", ['disposition' => 'move', 'target_location_id' => $to->id])
            ->assertRedirect();

        $this->assertDatabaseMissing('locations', ['id' => $from->id]);
        $this->assertEquals($to->id, $asset->fresh()->location_id);
        $this->assertEquals($to->id, $order->fresh()->location_id);
    }

    public function test_move_target_must_belong_to_the_same_customer(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $from = Location::factory()->create(['customer_id' => $customer->id]);
        $foreign = Location::factory()->create(['customer_id' => $other->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$from->id}", ['disposition' => 'move', 'target_location_id' => $foreign->id])
            ->assertSessionHasErrors('target_location_id');

        $this->assertDatabaseHas('locations', ['id' => $from->id]);
    }

    public function test_asset_location_can_be_assigned(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $asset = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id]);

        $this->actingAs($this->admin())
            ->patch("/assets/{$asset->id}/location", ['location_id' => $location->id])
            ->assertRedirect();

        $this->assertEquals($location->id, $asset->fresh()->location_id);
    }

    public function test_asset_location_rejects_a_location_of_another_customer(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $product = Product::factory()->create();
        $foreign = Location::factory()->create(['customer_id' => $other->id]);
        $asset = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id]);

        $this->actingAs($this->admin())
            ->patch("/assets/{$asset->id}/location", ['location_id' => $foreign->id])
            ->assertSessionHasErrors('location_id');

        $this->assertNull($asset->fresh()->location_id);
    }

    public function test_location_combo_is_allowed_with_customer_read(): void
    {
        $customer = Customer::factory()->create();
        Location::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->userWith('customer.read'))
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_location_combo_is_denied_without_a_relevant_permission(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertForbidden();
    }

    public function test_resolved_location_prefers_the_linked_location(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Dorpsstraat 1',
            'postal_code' => '1234AB',
            'city' => 'Utrecht',
        ]);
        $order = ServiceOrder::factory()->create([
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'execution_location' => 'Vrije invoer',
        ]);

        $this->assertEquals('Dorpsstraat 1, 1234AB Utrecht', $order->fresh()->resolved_location);
    }

    public function test_resolved_location_falls_back_to_free_text(): void
    {
        $customer = Customer::factory()->create();
        $order = ServiceOrder::factory()->create([
            'customer_id' => $customer->id,
            'location_id' => null,
            'execution_location' => 'Vrije invoer',
        ]);

        $this->assertEquals('Vrije invoer', $order->fresh()->resolved_location);
    }

    public function test_setting_a_location_clears_free_text_execution_location(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $order = ServiceOrder::factory()->create([
            'customer_id' => $customer->id,
            'execution_location' => 'Oude invoer',
        ]);

        $this->actingAs($this->admin())
            ->put("/serviceorders/{$order->id}", [
                'customer_id' => $customer->id,
                'location_id' => $location->id,
                'execution_location' => 'Zou genegeerd moeten worden',
            ])->assertRedirect();

        $fresh = $order->fresh();
        $this->assertEquals($location->id, $fresh->location_id);
        $this->assertNull($fresh->execution_location);
    }

    public function test_manual_generation_creates_one_order_per_location(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $loc_a = Location::factory()->create(['customer_id' => $customer->id]);
        $loc_b = Location::factory()->create(['customer_id' => $customer->id]);
        $asset_a = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'location_id' => $loc_a->id]);
        $asset_b = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'location_id' => $loc_b->id]);

        $contract = MaintenanceContract::factory()->create(['customer_id' => $customer->id]);
        $contract->assets()->attach([$asset_a->id, $asset_b->id]);

        $orders = app(MaintenanceContractServiceOrderGenerator::class)->generateNowForContract($contract->fresh());

        $this->assertCount(2, $orders);
        $this->assertEqualsCanonicalizing(
            [$loc_a->id, $loc_b->id],
            collect($orders)->pluck('location_id')->all()
        );
    }
}
