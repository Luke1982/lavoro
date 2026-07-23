<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AssetIndexOwningCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->create();

        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::create(['name' => 'asset-reader']);
        $permission = Permission::firstOrCreate(['name' => 'asset.read'], ['label' => 'asset.read']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $this->user = $user;
    }

    private function root(Customer $customer): Asset
    {
        return Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
        ]);
    }

    private function child(Asset $parent): Asset
    {
        return Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $parent->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_a_root_asset_exposes_its_own_customer_as_owner(): void
    {
        $customer = Customer::factory()->create();
        $this->root($customer);

        $this->actingAs($this->user)
            ->get(route('assets.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Assets/IndexPage')
                ->where('assets.data.0.owning_customer', [
                    'id' => $customer->id,
                    'name' => $customer->name,
                ]));
    }

    public function test_a_child_asset_resolves_the_owner_through_its_root(): void
    {
        $customer = Customer::factory()->create();
        $root = $this->root($customer);
        $child = $this->child($root);
        $grandchild = $this->child($child);

        $this->actingAs($this->user)
            ->get(route('assets.index'))
            ->assertInertia(function (AssertableInertia $page) use ($customer, $child, $grandchild) {
                $rows = collect($page->toArray()['props']['assets']['data']);

                $expected = ['id' => $customer->id, 'name' => $customer->name];

                $this->assertSame($expected, $rows->firstWhere('id', $child->id)['owning_customer']);
                $this->assertSame($expected, $rows->firstWhere('id', $grandchild->id)['owning_customer']);
            });
    }
}
