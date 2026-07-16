<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetOwnershipInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
    }

    private function root(array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
        ], $attributes));
    }

    private function child(Asset $parent, array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'customer_id' => null,
            'parent_asset_id' => $parent->id,
            'product_id' => $this->product->id,
        ], $attributes));
    }

    public function test_a_root_asset_has_a_customer_and_no_parent(): void
    {
        $root = $this->root();

        $this->assertSame($this->customer->id, $root->customer_id);
        $this->assertNull($root->parent_asset_id);
    }

    public function test_an_asset_cannot_have_both_a_customer_and_a_parent(): void
    {
        $root = $this->root();

        $this->expectException(DomainException::class);

        $this->child($root, ['customer_id' => $this->customer->id]);
    }

    public function test_an_asset_cannot_have_neither_a_customer_nor_a_parent(): void
    {
        $this->expectException(DomainException::class);

        $this->root(['customer_id' => null]);
    }

    public function test_an_existing_root_cannot_be_orphaned_by_clearing_its_customer(): void
    {
        $root = $this->root();

        $this->expectException(DomainException::class);

        $root->update(['customer_id' => null]);
    }

    public function test_a_child_resolves_its_customer_through_its_parent(): void
    {
        $child = $this->child($this->root());

        $this->assertNull($child->customer_id);
        $this->assertSame($this->customer->id, $child->resolvedCustomerId());
    }

    public function test_a_grandchild_resolves_its_customer_through_the_whole_chain(): void
    {
        $grandchild = $this->child($this->child($this->root()));

        $this->assertSame($this->customer->id, $grandchild->resolvedCustomerId());
    }

    public function test_a_child_resolves_its_location_through_its_parent(): void
    {
        $location = Location::factory()->create(['customer_id' => $this->customer->id]);
        $child = $this->child($this->root(['location_id' => $location->id]));

        $this->assertNull($child->location_id);
        $this->assertSame($location->id, $child->resolvedLocationId());
    }

    public function test_a_root_resolves_to_its_own_customer_and_location(): void
    {
        $location = Location::factory()->create(['customer_id' => $this->customer->id]);
        $root = $this->root(['location_id' => $location->id]);

        $this->assertSame($this->customer->id, $root->resolvedCustomerId());
        $this->assertSame($location->id, $root->resolvedLocationId());
    }

    public function test_a_root_may_have_no_location(): void
    {
        $root = $this->root(['location_id' => null]);

        $this->assertNull($root->resolvedLocationId());
    }
}
