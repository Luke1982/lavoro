<?php

namespace Tests\Feature\Location;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Location;
use App\Models\Product;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Deleting a location must never silently orphan the things attached to it: the
 * user chooses to move them to another location or detach them.
 */
class LocationDeletionTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    public function test_detaching_clears_the_location_on_assets_and_service_orders(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $asset = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
        ]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id, 'location_id' => $location->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$location->id}", ['disposition' => 'detach'])
            ->assertRedirect();

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
        $this->assertNull($asset->fresh()->location_id);
        $this->assertNull($order->fresh()->location_id);
    }

    public function test_moving_reassigns_assets_and_service_orders_to_the_target(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $from = Location::factory()->create(['customer_id' => $customer->id]);
        $to = Location::factory()->create(['customer_id' => $customer->id]);
        $asset = Asset::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'location_id' => $from->id,
        ]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id, 'location_id' => $from->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$from->id}", ['disposition' => 'move', 'target_location_id' => $to->id])
            ->assertRedirect();

        $this->assertDatabaseMissing('locations', ['id' => $from->id]);
        $this->assertEquals($to->id, $asset->fresh()->location_id);
        $this->assertEquals($to->id, $order->fresh()->location_id);
    }

    public function test_events_are_moved_to_the_target(): void
    {
        $customer = Customer::factory()->create();
        $from = Location::factory()->create(['customer_id' => $customer->id]);
        $to = Location::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create(['location_id' => $from->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$from->id}", ['disposition' => 'move', 'target_location_id' => $to->id])
            ->assertRedirect();

        $this->assertEquals($to->id, $event->fresh()->location_id);
    }

    public function test_events_are_detached(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create(['location_id' => $location->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$location->id}", ['disposition' => 'detach'])
            ->assertRedirect();

        $this->assertNull($event->fresh()->location_id);
    }

    public function test_the_move_target_must_belong_to_the_same_customer(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $from = Location::factory()->create(['customer_id' => $customer->id]);
        $foreign = Location::factory()->create(['customer_id' => $other->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$from->id}", ['disposition' => 'move', 'target_location_id' => $foreign->id])
            ->assertSessionHasErrors('target_location_id');

        $this->assertDatabaseHas('locations', ['id' => $from->id]);
    }
}
