<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Location;
use App\Models\MaintenanceContract;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Google\EventPayloadBuilder;
use App\Services\MaintenanceContractServiceOrderGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'admin'])->id);

        return $user;
    }

    private function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'role-' . $permission]);
        $perm = Permission::firstOrCreate(['name' => $permission], ['label' => $permission]);
        $role->permissions()->attach($perm->id);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_deleting_a_location_detaches_assets_and_service_orders(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $asset = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'location_id' => $location->id]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id, 'location_id' => $location->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$location->id}", ['disposition' => 'detach'])
            ->assertRedirect();

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
        $this->assertNull($asset->fresh()->location_id);
        $this->assertNull($order->fresh()->location_id);
    }

    public function test_deleting_a_location_moves_assets_and_service_orders_to_the_target(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $from = Location::factory()->create(['customer_id' => $customer->id]);
        $to = Location::factory()->create(['customer_id' => $customer->id]);
        $asset = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'location_id' => $from->id]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id, 'location_id' => $from->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$from->id}", ['disposition' => 'move', 'target_location_id' => $to->id])
            ->assertRedirect();

        $this->assertDatabaseMissing('locations', ['id' => $from->id]);
        $this->assertEquals($to->id, $asset->fresh()->location_id);
        $this->assertEquals($to->id, $order->fresh()->location_id);
    }

    public function test_move_target_must_belong_to_the_same_customer(): void
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

    public function test_asset_location_can_be_assigned(): void
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

    public function test_asset_location_rejects_a_location_of_another_customer(): void
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

    public function test_location_combo_is_allowed_with_customer_read(): void
    {
        $customer = Customer::factory()->create();
        Location::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->userWith('customer.read'))
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_location_combo_is_denied_without_a_relevant_permission(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/combo/customers/{$customer->id}/locations")
            ->assertForbidden();
    }

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

    public function test_setting_a_location_clears_free_text_execution_location(): void
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

    public function test_google_payload_uses_the_werkbon_location_over_the_event_free_text(): void
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
        ]);
        $event = Event::factory()->create(['location' => 'Oude vrije invoer']);
        $event->serviceOrders()->attach($order->id);

        $payload = app(EventPayloadBuilder::class)->build($event->fresh());

        $this->assertEquals('Dorpsstraat 1, 1234AB Utrecht', $payload['location']);
    }

    public function test_google_payload_falls_back_to_the_event_location_without_a_werkbon_location(): void
    {
        $customer = Customer::factory()->create();
        $order = ServiceOrder::factory()->create([
            'customer_id' => $customer->id,
            'location_id' => null,
        ]);
        $event = Event::factory()->create(['location' => 'Vrije invoer']);
        $event->serviceOrders()->attach($order->id);

        $payload = app(EventPayloadBuilder::class)->build($event->fresh());

        $this->assertEquals('Vrije invoer', $payload['location']);
    }

    public function test_event_resolved_location_prefers_its_linked_location(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Kerkweg 9',
            'postal_code' => '9999ZZ',
            'city' => 'Groningen',
        ]);
        $event = Event::factory()->create([
            'location' => 'Oude tekst',
            'location_id' => $location->id,
        ]);

        $this->assertEquals('Kerkweg 9, 9999ZZ Groningen', $event->fresh()->resolved_location);
    }

    public function test_google_payload_prefers_the_events_own_linked_location(): void
    {
        $customer = Customer::factory()->create();
        $order_location = Location::factory()->create(['customer_id' => $customer->id, 'address' => 'Werkbonweg 1']);
        $event_location = Location::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Afspraakweg 2',
            'postal_code' => '1111AA',
            'city' => 'Assen',
        ]);
        $order = ServiceOrder::factory()->create([
            'customer_id' => $customer->id,
            'location_id' => $order_location->id,
        ]);
        $event = Event::factory()->create([
            'location' => 'Vrije tekst',
            'location_id' => $event_location->id,
        ]);
        $event->serviceOrders()->attach($order->id);

        $payload = app(EventPayloadBuilder::class)->build($event->fresh());

        $this->assertEquals('Afspraakweg 2, 1111AA Assen', $payload['location']);
    }

    public function test_deleting_a_location_moves_events_to_the_target(): void
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

    public function test_deleting_a_location_detaches_events(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create(['location_id' => $location->id]);

        $this->actingAs($this->admin())
            ->delete("/locations/{$location->id}", ['disposition' => 'detach'])
            ->assertRedirect();

        $this->assertNull($event->fresh()->location_id);
    }

    public function test_copying_an_event_keeps_its_linked_location(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create(['location_id' => $location->id]);
        $event->serviceOrders()->attach($order->id);

        $this->actingAs($this->admin())
            ->postJson("/api/events/{$event->id}/copy", ['offsets' => [7]])
            ->assertSuccessful();

        $copy = Event::where('id', '!=', $event->id)->latest('id')->first();
        $this->assertNotNull($copy);
        $this->assertEquals($location->id, $copy->location_id);
    }

    public function test_event_location_must_belong_to_the_events_customer(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $foreign = Location::factory()->create(['customer_id' => $other->id]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create();
        $event->serviceOrders()->attach($order->id);

        $this->actingAs($this->admin())
            ->putJson("/api/events/{$event->id}", ['location_id' => $foreign->id])
            ->assertJsonValidationErrors('location_id');

        $this->assertNull($event->fresh()->location_id);
    }

    public function test_event_location_is_rejected_when_no_customer_can_be_resolved(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create();

        $this->actingAs($this->admin())
            ->putJson("/api/events/{$event->id}", ['location_id' => $location->id])
            ->assertJsonValidationErrors('location_id');

        $this->assertNull($event->fresh()->location_id);
    }

    public function test_linking_a_location_to_an_event_clears_its_free_text(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create(['location' => 'Oude tekst']);
        $event->serviceOrders()->attach($order->id);

        $this->actingAs($this->admin())
            ->putJson("/api/events/{$event->id}", [
                'location_id' => $location->id,
                'location' => 'Zou genegeerd moeten worden',
            ])->assertSuccessful();

        $fresh = $event->fresh();
        $this->assertEquals($location->id, $fresh->location_id);
        $this->assertNull($fresh->location);
    }

    public function test_event_search_finds_and_shows_a_linked_location(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Vestiging Zwolle',
            'address' => 'Industrieweg 5',
            'postal_code' => '8000AA',
            'city' => 'Zwolle',
        ]);
        Event::factory()->create([
            'location' => null,
            'location_id' => $location->id,
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHour(),
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/events/search?q=Industrieweg')
            ->assertOk()
            ->assertJsonFragment(['location' => 'Industrieweg 5, 8000AA Zwolle']);
    }

    public function test_manual_generation_creates_one_order_per_location(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $loc_a = Location::factory()->create(['customer_id' => $customer->id]);
        $loc_b = Location::factory()->create(['customer_id' => $customer->id]);
        $asset_a = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'location_id' => $loc_a->id]);
        $asset_b = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'location_id' => $loc_b->id]);

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
