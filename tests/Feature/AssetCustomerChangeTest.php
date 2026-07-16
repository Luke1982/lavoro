<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MaintenanceContract;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetCustomerChangeTest extends TestCase
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
        $role = Role::create(['name' => 'asset-editor']);
        $permission = Permission::firstOrCreate(['name' => 'asset.update'], ['label' => 'asset.update']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $this->user = $user;
    }

    private function root(array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'customer_id' => $this->old_customer->id,
            'product_id' => $this->product->id,
        ], $attributes));
    }

    private function payloadFor(Asset $asset, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $asset->product_id,
            'serial_number' => $asset->serial_number,
            'customer_id' => $asset->customer_id,
            'status' => 'Actief',
        ], $overrides);
    }

    public function test_changing_the_customer_without_a_strategy_is_rejected(): void
    {
        $asset = $this->root();

        $response = $this->actingAs($this->user)->put(
            route('assets.update', $asset),
            $this->payloadFor($asset, ['customer_id' => $this->new_customer->id])
        );

        $response->assertSessionHasErrors('asset_strategy');
        $this->assertSame($this->old_customer->id, $asset->refresh()->customer_id);
    }

    public function test_transferring_moves_the_machine_and_its_children(): void
    {
        $asset = $this->root();
        $child = Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $asset->id,
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->user)->put(
            route('assets.update', $asset),
            $this->payloadFor($asset, [
                'customer_id' => $this->new_customer->id,
                'asset_strategy' => 'transfer',
            ])
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame($this->new_customer->id, $asset->refresh()->customer_id);
        $this->assertNull($child->refresh()->customer_id);
        $this->assertSame($this->new_customer->id, $child->resolvedCustomerId());
    }

    public function test_transferring_detaches_the_machine_from_the_old_customers_contract(): void
    {
        $asset = $this->root();
        $contract = MaintenanceContract::factory()->create(['customer_id' => $this->old_customer->id]);
        $contract->assets()->attach($asset->id);

        $this->actingAs($this->user)->put(
            route('assets.update', $asset),
            $this->payloadFor($asset, [
                'customer_id' => $this->new_customer->id,
                'asset_strategy' => 'transfer',
            ])
        );

        $this->assertFalse($contract->assets()->where('assets.id', $asset->id)->exists());
    }

    public function test_the_location_map_is_applied(): void
    {
        $old_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        $new_location = Location::factory()->create(['customer_id' => $this->new_customer->id]);
        $asset = $this->root(['location_id' => $old_location->id]);

        $this->actingAs($this->user)->put(
            route('assets.update', $asset),
            $this->payloadFor($asset, [
                'customer_id' => $this->new_customer->id,
                'asset_strategy' => 'transfer',
                'location_map' => [$old_location->id => $new_location->id],
            ])
        );

        $this->assertSame($new_location->id, $asset->refresh()->location_id);
    }

    public function test_editing_a_machine_without_changing_its_customer_needs_no_strategy(): void
    {
        $asset = $this->root();

        $response = $this->actingAs($this->user)->put(
            route('assets.update', $asset),
            $this->payloadFor($asset, ['serial_number' => 'SN-EDITED'])
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('SN-EDITED', $asset->refresh()->serial_number);
        $this->assertSame($this->old_customer->id, $asset->customer_id);
    }
}
