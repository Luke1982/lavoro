<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventSearchApiTest extends TestCase
{
    use RefreshDatabase;

    private ?EventType $event_type = null;

    private function event_type(): EventType
    {
        return $this->event_type ??= EventType::factory()->create();
    }

    private function search_user(array $permissions = []): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'planner']);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user;
    }

    private function event_for_customer(string $customer_name, array $overrides = []): Event
    {
        $customer = Customer::factory()->create(['name' => $customer_name]);
        $service_order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $event = Event::factory()->create(array_merge(['event_type_id' => $this->event_type()->id], $overrides));
        $event->serviceOrders()->attach($service_order->id);

        return $event->fresh();
    }

    public function test_search_is_forbidden_without_event_read_permission(): void
    {
        $user = $this->search_user([]);

        $this->getJson('/api/events/search?q=aa', [])
            ->assertUnauthorized();

        $this->actingAs($user)->getJson('/api/events/search?q=aa')
            ->assertForbidden();
    }

    public function test_search_requires_at_least_two_characters(): void
    {
        $user = $this->search_user(['event.read', 'event.see_all']);
        $this->event_for_customer('Van der Berg Installatie');

        $response = $this->actingAs($user)->getJson('/api/events/search?q=v');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_search_with_see_all_finds_events_regardless_of_owner_or_executor(): void
    {
        $owner = $this->search_user(['event.read', 'event.see_all']);
        $other_user = User::factory()->create();

        $event = $this->event_for_customer('Van der Berg Installatie');
        $event->addExecutingUser($other_user->id);

        $response = $this->actingAs($owner)->getJson('/api/events/search?q=Berg');

        $response->assertOk();
        $this->assertContains($event->id, collect($response->json())->pluck('id')->all());
    }

    public function test_search_without_see_all_only_finds_own_events(): void
    {
        $searcher = $this->search_user(['event.read']);
        $other_user = User::factory()->create();

        $own_event = $this->event_for_customer('Van der Berg Installatie');
        $own_event->addExecutingUser($searcher->id);

        $others_event = $this->event_for_customer('Van der Wetering BV');
        $others_event->addExecutingUser($other_user->id);

        $response = $this->actingAs($searcher)->getJson('/api/events/search?q=Van');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($own_event->id, $ids);
        $this->assertNotContains($others_event->id, $ids);
    }

    public function test_search_without_see_all_finds_events_owned_but_not_executed(): void
    {
        $searcher = $this->search_user(['event.read']);
        $other_user = User::factory()->create();

        $this->actingAs($searcher);
        $owned_event = $this->event_for_customer('Van der Berg Installatie');
        $owned_event->addExecutingUser($other_user->id);

        $response = $this->actingAs($searcher)->getJson('/api/events/search?q=Berg');

        $response->assertOk();
        $this->assertContains($owned_event->id, collect($response->json())->pluck('id')->all());
    }

    public function test_search_by_service_order_number_does_not_loosely_match_unrelated_text(): void
    {
        $user = $this->search_user(['event.read', 'event.see_all']);

        // Pad the auto-increment id to two digits, since the search endpoint
        // requires a query of at least 2 characters.
        for ($i = 0; $i < 9; $i++) {
            $this->event_for_customer('Filler BV');
        }

        $matching_event = $this->event_for_customer('Zeeman Techniek');
        $service_order_id = $matching_event->serviceOrders()->first()->id;
        $this->assertGreaterThanOrEqual(10, $service_order_id);

        $unrelated_event = $this->event_for_customer('Onbekende Klant BV');

        $response = $this->actingAs($user)->getJson('/api/events/search?q=' . $service_order_id);

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertEquals([$matching_event->id], $ids);
        $this->assertNotContains($unrelated_event->id, $ids);
    }

    public function test_search_excludes_events_beyond_current_week_without_permission(): void
    {
        $searcher = $this->search_user(['event.read', 'event.see_all']);

        $near_event = $this->event_for_customer('Van der Berg Installatie', [
            'start' => now()->addDays(2),
            'end' => now()->addDays(2)->addHour(),
        ]);
        $far_event = $this->event_for_customer('Van der Wetering BV', [
            'start' => now()->addDays(30),
            'end' => now()->addDays(30)->addHour(),
        ]);

        $response = $this->actingAs($searcher)->getJson('/api/events/search?q=Van');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($near_event->id, $ids);
        $this->assertNotContains($far_event->id, $ids);
    }

    public function test_search_includes_far_future_events_with_permission(): void
    {
        $searcher = $this->search_user(['event.read', 'event.see_all', 'event.see_beyond_current_week']);

        $far_event = $this->event_for_customer('Van der Wetering BV', [
            'start' => now()->addDays(30),
            'end' => now()->addDays(30)->addHour(),
        ]);

        $response = $this->actingAs($searcher)->getJson('/api/events/search?q=Van');

        $response->assertOk();
        $this->assertContains($far_event->id, collect($response->json())->pluck('id')->all());
    }
}
