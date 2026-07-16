<?php

namespace Tests\Feature\Location;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Location;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\EventLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the escalation order in EventLocationResolver — the single definition of
 * "where does this appointment happen", relied on by the Google Calendar payload,
 * the planner export and the planner UI.
 *
 * There is one test per rung of the ladder, each one setting up every lower rung
 * as well, so a reordering cannot pass unnoticed:
 *
 *   1. the appointment's linked location
 *   2. the werkbon's linked location
 *   3. the appointment's free text
 *   4. the werkbon's free text
 *   5. the project's location
 *   6. the customer's address
 */
class EventLocationResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(Event $event): ?string
    {
        return app(EventLocationResolver::class)->resolve($event->fresh());
    }

    private function customer(): Customer
    {
        return Customer::factory()->create([
            'address' => 'Klantweg 9',
            'postal_code' => '5555EE',
            'city' => 'Breda',
        ]);
    }

    private function orderFor(Customer $customer, array $attributes = []): ServiceOrder
    {
        $project = Project::create([
            'title' => 'Project X',
            'location' => 'Projectlaan 4',
            'customer_id' => $customer->id,
            'project_manager_id' => User::factory()->create()->id,
            'status' => 'Lopend',
        ]);

        return ServiceOrder::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'execution_location' => 'Werkbon vrije tekst',
        ], $attributes));
    }

    public function test_rung_1_the_appointments_own_linked_location_wins(): void
    {
        $customer = $this->customer();
        $order_location = Location::factory()->create(['customer_id' => $customer->id, 'address' => 'Werkbonweg 1']);
        $event_location = Location::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Afspraakweg 2',
            'postal_code' => '1111AA',
            'city' => 'Assen',
        ]);
        $order = $this->orderFor($customer, ['location_id' => $order_location->id]);
        $event = Event::factory()->create(['location' => 'Afspraak vrije tekst', 'location_id' => $event_location->id]);
        $event->serviceOrders()->attach($order->id);

        $this->assertEquals('Afspraakweg 2, 1111AA Assen', $this->resolve($event));
    }

    public function test_rung_2_the_werkbons_linked_location_beats_free_text(): void
    {
        $customer = $this->customer();
        $order_location = Location::factory()->create([
            'customer_id' => $customer->id,
            'address' => 'Werkbonweg 1',
            'postal_code' => '2222BB',
            'city' => 'Zwolle',
        ]);
        $order = $this->orderFor($customer, ['location_id' => $order_location->id]);
        $event = Event::factory()->create(['location' => 'Afspraak vrije tekst', 'location_id' => null]);
        $event->serviceOrders()->attach($order->id);

        $this->assertEquals('Werkbonweg 1, 2222BB Zwolle', $this->resolve($event));
    }

    public function test_rung_3_the_appointments_free_text_beats_the_werkbons(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer);
        $event = Event::factory()->create(['location' => 'Afspraak vrije tekst', 'location_id' => null]);
        $event->serviceOrders()->attach($order->id);

        $this->assertEquals('Afspraak vrije tekst', $this->resolve($event));
    }

    public function test_rung_4_the_werkbons_free_text_beats_the_project(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer);
        $event = Event::factory()->create(['location' => null, 'location_id' => null]);
        $event->serviceOrders()->attach($order->id);

        $this->assertEquals('Werkbon vrije tekst', $this->resolve($event));
    }

    public function test_rung_5_the_projects_location_beats_the_customer(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer, ['execution_location' => null]);
        $event = Event::factory()->create(['location' => null, 'location_id' => null]);
        $event->serviceOrders()->attach($order->id);

        $this->assertEquals('Projectlaan 4', $this->resolve($event));
    }

    public function test_rung_6_falls_back_to_the_customers_address(): void
    {
        $customer = $this->customer();
        $order = ServiceOrder::factory()->create([
            'customer_id' => $customer->id,
            'execution_location' => null,
            'project_id' => null,
        ]);
        $event = Event::factory()->create(['location' => null, 'location_id' => null]);
        $event->serviceOrders()->attach($order->id);

        $this->assertEquals('Klantweg 9, 5555EE Breda', $this->resolve($event));
    }

    public function test_resolves_to_null_when_there_is_nothing_to_go_on(): void
    {
        $event = Event::factory()->create(['location' => null, 'location_id' => null]);

        $this->assertNull($this->resolve($event));
    }

    public function test_a_customer_attached_without_a_werkbon_still_resolves(): void
    {
        $customer = $this->customer();
        $event = Event::factory()->create(['location' => null, 'location_id' => null]);
        $event->customers()->attach($customer->id);

        $this->assertEquals('Klantweg 9, 5555EE Breda', $this->resolve($event));
    }
}
