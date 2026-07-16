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

class MaintenanceContractCustomerChangeTest extends TestCase
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
        $role = Role::create(['name' => 'contract-manager']);
        $permission = Permission::firstOrCreate(
            ['name' => 'maintenancecontract.update'],
            ['label' => 'maintenancecontract.update']
        );
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $this->user = $user;
    }

    private function contractWithAsset(array $asset_attributes = []): array
    {
        $contract = MaintenanceContract::factory()->create(['customer_id' => $this->old_customer->id]);
        $asset = Asset::factory()->create(array_merge([
            'customer_id' => $this->old_customer->id,
            'product_id' => $this->product->id,
        ], $asset_attributes));
        $contract->assets()->attach($asset->id);

        return [$contract, $asset];
    }

    public function test_changing_the_customer_without_a_strategy_is_rejected(): void
    {
        [$contract, $asset] = $this->contractWithAsset();

        $response = $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            ['customer_id' => $this->new_customer->id]
        );

        $response->assertSessionHasErrors('asset_strategy');
        $this->assertSame($this->old_customer->id, $contract->refresh()->customer_id);
        $this->assertSame($this->old_customer->id, $asset->refresh()->customer_id);
    }

    public function test_an_unknown_strategy_is_rejected(): void
    {
        [$contract] = $this->contractWithAsset();

        $response = $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'detach']
        );

        $response->assertSessionHasErrors('asset_strategy');
        $this->assertSame($this->old_customer->id, $contract->refresh()->customer_id);
    }

    public function test_transferring_moves_the_contracts_machines_to_the_new_customer(): void
    {
        [$contract, $asset] = $this->contractWithAsset();

        $response = $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'transfer']
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame($this->new_customer->id, $contract->refresh()->customer_id);
        $this->assertSame($this->new_customer->id, $asset->refresh()->customer_id);
    }

    public function test_the_contract_keeps_its_machines_after_a_transfer(): void
    {
        [$contract, $asset] = $this->contractWithAsset();

        $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'transfer']
        );

        $this->assertTrue($contract->assets()->where('assets.id', $asset->id)->exists());
    }

    public function test_another_contract_of_the_old_customer_loses_the_machine(): void
    {
        [$contract, $asset] = $this->contractWithAsset();
        $other = MaintenanceContract::factory()->create(['customer_id' => $this->old_customer->id]);
        $other->assets()->attach($asset->id);

        $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'transfer']
        );

        $this->assertFalse($other->assets()->where('assets.id', $asset->id)->exists());
    }

    public function test_the_location_map_is_applied_to_the_transferred_machines(): void
    {
        $old_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        $new_location = Location::factory()->create(['customer_id' => $this->new_customer->id]);
        [$contract, $asset] = $this->contractWithAsset(['location_id' => $old_location->id]);

        $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            [
                'customer_id' => $this->new_customer->id,
                'asset_strategy' => 'transfer',
                'location_map' => [$old_location->id => $new_location->id],
            ]
        );

        $this->assertSame($new_location->id, $asset->refresh()->location_id);
    }

    public function test_a_location_belonging_to_someone_else_is_rejected(): void
    {
        $old_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        $foreign_location = Location::factory()->create(['customer_id' => $this->old_customer->id]);
        [$contract, $asset] = $this->contractWithAsset(['location_id' => $old_location->id]);

        $response = $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            [
                'customer_id' => $this->new_customer->id,
                'asset_strategy' => 'transfer',
                'location_map' => [$old_location->id => $foreign_location->id],
            ]
        );

        $response->assertSessionHasErrors();
        $this->assertSame($this->old_customer->id, $asset->refresh()->customer_id);
    }

    public function test_a_contract_without_machines_needs_no_strategy(): void
    {
        $contract = MaintenanceContract::factory()->create(['customer_id' => $this->old_customer->id]);

        $response = $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            ['customer_id' => $this->new_customer->id]
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame($this->new_customer->id, $contract->refresh()->customer_id);
    }

    public function test_editing_another_field_never_asks_for_a_strategy(): void
    {
        [$contract] = $this->contractWithAsset();

        $response = $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            ['title' => 'Nieuwe titel']
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('Nieuwe titel', $contract->refresh()->title);
    }

    public function test_resubmitting_the_same_customer_is_not_a_change(): void
    {
        [$contract] = $this->contractWithAsset();

        $response = $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            ['customer_id' => $this->old_customer->id]
        );

        $response->assertSessionHasNoErrors();
    }

    public function test_the_transfer_is_logged_on_the_contract(): void
    {
        [$contract] = $this->contractWithAsset();

        $this->actingAs($this->user)->patch(
            route('maintenancecontracts.update', $contract),
            ['customer_id' => $this->new_customer->id, 'asset_strategy' => 'transfer']
        );

        $this->assertTrue(
            $contract->activities()->where('description', 'like', '%Klant gewijzigd%')->exists()
        );
    }
}
