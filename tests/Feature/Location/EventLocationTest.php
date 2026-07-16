<?php

namespace Tests\Feature\Location;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Location;
use App\Models\ServiceOrder;
use App\Services\Google\EventPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * An appointment's own location: linking, validation, copying, and the two
 * integration points (Google Calendar and the planner payload) that would
 * otherwise break silently.
 */
class EventLocationTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function customerWithOrder(): array
    {
        $customer = Customer::factory()->create();
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);

        return [$customer, $order];
    }

    public function test_resolved_location_prefers_the_linked_location(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Kerkweg 9',
            'postal_code' => '9999ZZ',
            'city' => 'Groningen',
        ]);
        $event = Event::factory()->create(['location' => 'Oude tekst', 'location_id' => $location->id]);

        $this->assertEquals('Kerkweg 9, 9999ZZ Groningen', $event->fresh()->resolved_location);
    }

    /**
     * Guards the relation's foreign key: naming it linkedLocation makes Eloquent
     * infer linked_location_id unless location_id is pinned explicitly.
     */
    public function test_the_linked_location_relation_resolves_through_location_id(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create(['location_id' => $location->id]);

        $this->assertNotNull($event->fresh()->linkedLocation);
        $this->assertEquals($location->id, $event->fresh()->linkedLocation->id);
    }

    public function test_linking_a_location_clears_the_free_text(): void
    {
        [$customer, $order] = $this->customerWithOrder();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
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

    public function test_a_location_of_another_customer_is_rejected(): void
    {
        [, $order] = $this->customerWithOrder();
        $other = Customer::factory()->create();
        $foreign = Location::factory()->create(['customer_id' => $other->id]);
        $event = Event::factory()->create();
        $event->serviceOrders()->attach($order->id);

        $this->actingAs($this->admin())
            ->putJson("/api/events/{$event->id}", ['location_id' => $foreign->id])
            ->assertJsonValidationErrors('location_id');

        $this->assertNull($event->fresh()->location_id);
    }

    public function test_a_location_is_rejected_when_no_customer_can_be_resolved(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create();

        $this->actingAs($this->admin())
            ->putJson("/api/events/{$event->id}", ['location_id' => $location->id])
            ->assertJsonValidationErrors('location_id');

        $this->assertNull($event->fresh()->location_id);
    }

    public function test_copying_an_event_keeps_its_linked_location(): void
    {
        [$customer, $order] = $this->customerWithOrder();
        $location = Location::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create(['location_id' => $location->id]);
        $event->serviceOrders()->attach($order->id);

        $this->actingAs($this->admin())
            ->postJson("/api/events/{$event->id}/copy", ['offsets' => [7]])
            ->assertSuccessful();

        $copy = Event::where('id', '!=', $event->id)->latest('id')->first();
        $this->assertNotNull($copy);
        $this->assertEquals($location->id, $copy->location_id);
    }

    public function test_the_google_payload_uses_the_linked_location(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Afspraakweg 2',
            'postal_code' => '1111AA',
            'city' => 'Assen',
        ]);
        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create(['location' => 'Vrije tekst', 'location_id' => $location->id]);
        $event->serviceOrders()->attach($order->id);

        $payload = app(EventPayloadBuilder::class)->build($event->fresh());

        $this->assertEquals('Afspraakweg 2, 1111AA Assen', $payload['location']);
    }

    /**
     * The planner reads display_location off the event payload; if the append is
     * ever dropped from index() the planner silently shows no location at all.
     */
    public function test_the_planner_payload_exposes_the_resolved_display_location(): void
    {
        $customer = Customer::factory()->create();
        $location = Location::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Plannerweg 3',
            'postal_code' => '3333CC',
            'city' => 'Deventer',
        ]);
        $event = Event::factory()->create([
            'location_id' => $location->id,
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHour(),
        ]);
        $event->customers()->attach($customer->id);

        $this->actingAs($this->admin())
            ->getJson('/api/events?start=' . now()->format('Y-m-d H:i:s') . '&end=' . now()->addDays(2)->format('Y-m-d H:i:s'))
            ->assertOk()
            ->assertJsonFragment(['display_location' => 'Plannerweg 3, 3333CC Deventer']);
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
}
