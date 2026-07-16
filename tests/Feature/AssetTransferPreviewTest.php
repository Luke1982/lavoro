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

class AssetTransferPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Customer $old_customer;

    private Customer $new_customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->old_customer = Customer::factory()->create();
        $this->new_customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
    }

    private function userWith(string ...$permission_names): User
    {
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::create(['name' => 'tester-' . uniqid()]);

        foreach ($permission_names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    private function root(array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'customer_id' => $this->old_customer->id,
            'product_id' => $this->product->id,
        ], $attributes));
    }

    public function test_the_contract_context_previews_the_machines_that_would_move(): void
    {
        $asset = $this->root();
        $contract = MaintenanceContract::factory()->create(['customer_id' => $this->old_customer->id]);
        $contract->assets()->attach($asset->id);

        $response = $this->actingAs($this->userWith('maintenancecontract.update'))
            ->postJson(route('assets.transferPreview'), [
                'context' => 'contract',
                'id' => $contract->id,
                'customer_id' => $this->new_customer->id,
            ]);

        $response->assertOk();
        $response->assertJsonPath('assets.0.id', $asset->id);
    }

    public function test_the_preview_offers_the_new_customers_locations_for_mapping(): void
    {
        $old_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        $new_location = Location::factory()->create(['customer_id' => $this->new_customer->id]);
        $asset = $this->root(['location_id' => $old_location->id]);
        $contract = MaintenanceContract::factory()->create(['customer_id' => $this->old_customer->id]);
        $contract->assets()->attach($asset->id);

        $response = $this->actingAs($this->userWith('maintenancecontract.update'))
            ->postJson(route('assets.transferPreview'), [
                'context' => 'contract',
                'id' => $contract->id,
                'customer_id' => $this->new_customer->id,
            ]);

        $response->assertJsonPath('locations.0.id', $old_location->id);
        $response->assertJsonPath('target_locations.0.id', $new_location->id);
    }

    public function test_the_serviceorder_context_previews_the_machines_behind_its_jobs(): void
    {
        $asset = $this->root();
        $order = ServiceOrder::factory()->create(['customer_id' => $this->old_customer->id]);
        ServiceJob::factory()->create(['asset_id' => $asset->id, 'service_order_id' => $order->id]);

        $response = $this->actingAs($this->userWith('serviceorder.update'))
            ->postJson(route('assets.transferPreview'), [
                'context' => 'serviceorder',
                'id' => $order->id,
                'customer_id' => $this->new_customer->id,
            ]);

        $response->assertOk();
        $response->assertJsonPath('assets.0.id', $asset->id);
    }

    public function test_the_asset_context_previews_the_machine_and_its_children(): void
    {
        $root = $this->root();
        $child = Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $root->id,
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->userWith('asset.update'))
            ->postJson(route('assets.transferPreview'), [
                'context' => 'asset',
                'id' => $root->id,
                'customer_id' => $this->new_customer->id,
            ]);

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            [$root->id, $child->id],
            collect($response->json('assets'))->pluck('id')->all()
        );
    }

    public function test_the_preview_names_the_contracts_that_would_lose_the_machine(): void
    {
        $asset = $this->root();
        $other = MaintenanceContract::factory()->create(['customer_id' => $this->old_customer->id]);
        $other->assets()->attach($asset->id);

        $response = $this->actingAs($this->userWith('asset.update'))
            ->postJson(route('assets.transferPreview'), [
                'context' => 'asset',
                'id' => $asset->id,
                'customer_id' => $this->new_customer->id,
            ]);

        $response->assertJsonPath('contracts.0.id', $other->id);
    }

    public function test_the_preview_requires_permission_on_the_underlying_record(): void
    {
        $asset = $this->root();

        $response = $this->actingAs($this->userWith('asset.read'))
            ->postJson(route('assets.transferPreview'), [
                'context' => 'asset',
                'id' => $asset->id,
                'customer_id' => $this->new_customer->id,
            ]);

        $response->assertForbidden();
    }

    public function test_an_unknown_context_is_rejected(): void
    {
        $response = $this->actingAs($this->userWith('asset.update'))
            ->postJson(route('assets.transferPreview'), [
                'context' => 'nonsense',
                'id' => 1,
                'customer_id' => $this->new_customer->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_the_preview_does_not_move_anything(): void
    {
        $asset = $this->root();

        $this->actingAs($this->userWith('asset.update'))
            ->postJson(route('assets.transferPreview'), [
                'context' => 'asset',
                'id' => $asset->id,
                'customer_id' => $this->new_customer->id,
            ]);

        $this->assertSame($this->old_customer->id, $asset->refresh()->customer_id);
    }
}
