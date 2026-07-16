<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAssetTreeTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->create();
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

    public function test_the_tree_contains_the_customers_root_assets(): void
    {
        $customer = Customer::factory()->create();
        $root = $this->root($customer);

        $this->assertEqualsCanonicalizing([$root->id], $customer->assetTree()->pluck('id')->all());
    }

    public function test_the_tree_reaches_children_and_grandchildren(): void
    {
        $customer = Customer::factory()->create();
        $root = $this->root($customer);
        $child = $this->child($root);
        $grandchild = $this->child($child);

        $this->assertEqualsCanonicalizing(
            [$root->id, $child->id, $grandchild->id],
            $customer->assetTree()->pluck('id')->all()
        );
    }

    public function test_the_tree_excludes_another_customers_assets(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $root = $this->root($customer);
        $child = $this->child($root);

        $other_root = $this->root($other);
        $this->child($other_root);

        $this->assertEqualsCanonicalizing(
            [$root->id, $child->id],
            $customer->assetTree()->pluck('id')->all()
        );
    }

    public function test_a_customer_without_assets_gets_an_empty_tree(): void
    {
        $customer = Customer::factory()->create();

        $this->assertTrue($customer->assetTree()->isEmpty());
    }

    public function test_the_tree_eager_loads_requested_relations(): void
    {
        $customer = Customer::factory()->create();
        $this->child($this->root($customer));

        $tree = $customer->assetTree(['product']);

        $this->assertTrue($tree->every(fn (Asset $asset) => $asset->relationLoaded('product')));
    }

    public function test_assets_relation_still_returns_roots_only(): void
    {
        $customer = Customer::factory()->create();
        $root = $this->root($customer);
        $this->child($root);

        $this->assertEqualsCanonicalizing([$root->id], $customer->assets->pluck('id')->all());
    }
}
