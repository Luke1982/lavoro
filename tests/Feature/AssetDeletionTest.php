<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Asset;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AssetDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function authenticatedUserCanDeleteAnAsset()
    {
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::create(['name' => 'asset-manager']);
        $permission = Permission::firstOrCreate(['name' => 'asset.delete'], ['label' => 'asset.delete']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $product    = Product::factory()->create();
        $customer   = Customer::factory()->create();

        $asset = Asset::factory()->create([
            'product_id'  => $product->id,
            'customer_id' => $customer->id,
        ]);

        $response = $this->actingAs($user)->delete(route('assets.destroy', $asset));

        $response->assertRedirect(route('assets.index'));
        $response->assertSessionHas('success', 'Machine verwijderd.');
        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
    }
}
