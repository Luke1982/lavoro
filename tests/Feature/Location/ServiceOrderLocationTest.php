<?php

namespace Tests\Feature\Location;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MaintenanceContract;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Services\MaintenanceContractServiceOrderGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A werkbon's location: a linked location beats the free-text field, the two can
 * never coexist, and generated werkbonnen stay location-coherent.
 */
class ServiceOrderLocationTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

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

    public function test_linking_a_location_clears_the_free_text(): void
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

    public function test_generation_creates_one_werkbon_per_location(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $loc_a = Location::factory()->create(['customer_id' => $customer->id]);
        $loc_b = Location::factory()->create(['customer_id' => $customer->id]);
        $asset_a = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'location_id' => $loc_a->id,
        ]);
        $asset_b = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'location_id' => $loc_b->id,
        ]);

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
