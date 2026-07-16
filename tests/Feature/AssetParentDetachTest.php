<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductRelation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetParentDetachTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
        $this->location = Location::factory()->create(['customer_id' => $this->customer->id]);
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
            'customer_id' => $this->customer->id,
            'location_id' => $this->location->id,
            'product_id' => $this->product->id,
        ], $attributes));
    }

    private function child(Asset $parent): Asset
    {
        return Asset::factory()->create([
            'customer_id' => null,
            'parent_asset_id' => $parent->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_detaching_a_child_gives_it_the_former_roots_customer_and_location(): void
    {
        $child = $this->child($this->root());
        $user = $this->userWith('assetrelation.delete');

        $response = $this->actingAs($user)->delete(route('assets.detachParent', $child));

        $response->assertSessionHas('success');
        $child->refresh();

        $this->assertNull($child->parent_asset_id);
        $this->assertSame($this->customer->id, $child->customer_id);
        $this->assertSame($this->location->id, $child->location_id);
    }

    public function test_detaching_a_grandchild_inherits_from_the_root_not_the_middle_node(): void
    {
        $grandchild = $this->child($this->child($this->root()));
        $user = $this->userWith('assetrelation.delete');

        $this->actingAs($user)->delete(route('assets.detachParent', $grandchild));

        $grandchild->refresh();

        $this->assertNull($grandchild->parent_asset_id);
        $this->assertSame($this->customer->id, $grandchild->customer_id);
        $this->assertSame($this->location->id, $grandchild->location_id);
    }

    public function test_a_detached_child_keeps_its_own_children_beneath_it(): void
    {
        $child = $this->child($this->root());
        $grandchild = $this->child($child);
        $user = $this->userWith('assetrelation.delete');

        $this->actingAs($user)->delete(route('assets.detachParent', $child));

        $grandchild->refresh();

        $this->assertSame($child->id, $grandchild->parent_asset_id);
        $this->assertNull($grandchild->customer_id);
        $this->assertSame($this->customer->id, $grandchild->resolvedCustomerId());
    }

    public function test_the_relation_type_is_cleared_when_the_parent_goes(): void
    {
        $relation = ProductRelation::create(['name' => 'Binnendeel']);
        $child = $this->child($this->root());
        $child->update(['product_relation_id' => $relation->id]);
        $user = $this->userWith('assetrelation.delete');

        $this->actingAs($user)->delete(route('assets.detachParent', $child));

        $child->refresh();

        $this->assertNull($child->product_relation_id);
        $this->assertNull($child->productable_id);
    }

    public function test_detaching_a_root_is_rejected(): void
    {
        $root = $this->root();
        $user = $this->userWith('assetrelation.delete');

        $response = $this->actingAs($user)->delete(route('assets.detachParent', $root));

        $response->assertSessionHas('error');
        $this->assertSame($this->customer->id, $root->refresh()->customer_id);
    }

    public function test_detaching_requires_permission(): void
    {
        $child = $this->child($this->root());
        $user = $this->userWith('asset.read');

        $response = $this->actingAs($user)->delete(route('assets.detachParent', $child));

        $response->assertSessionHas('error');
        $this->assertSame($child->parent_asset_id, $child->refresh()->parent_asset_id);
    }
}
