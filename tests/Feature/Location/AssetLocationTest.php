<?php

namespace Tests\Feature\Location;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A machine can sit at one of its own customer's locations — and nowhere else.
 */
class AssetLocationTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    public function test_a_location_can_be_assigned_to_an_asset(): void
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

    public function test_a_location_of_another_customer_is_rejected(): void
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
}
