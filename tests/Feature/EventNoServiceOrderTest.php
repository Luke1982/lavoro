<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventNoServiceOrderTest extends TestCase
{
    use RefreshDatabase;

    private function admin_user(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_event_can_be_created_without_service_order(): void
    {
        $admin = $this->admin_user();
        $type = EventType::factory()->create();

        $customer = Customer::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/events', [
            'event_type_id' => $type->id,
            'status' => 'Gepland',
            'start' => now()->format('Y-m-d H:i'),
            'end' => now()->addHour()->format('Y-m-d H:i'),
            'no_service_order' => true,
            'customer_id' => $customer->id,
            'executing_user_ids' => [$admin->id],
        ]);

        $response->assertCreated();
        $event = Event::first();
        $this->assertTrue((bool) $event->no_service_order);
        $this->assertNull($event->eventable_id);
        $this->assertNull($event->eventable_type);
        $this->assertDatabaseCount('service_orders', 0);
        $this->assertEquals($customer->id, $event->customers()->first()?->id);
    }

    public function test_no_service_order_event_can_be_updated_without_werkbon(): void
    {
        $admin = $this->admin_user();
        $type = EventType::factory()->create();
        $customer = Customer::factory()->create();

        $create = $this->actingAs($admin)->postJson('/api/events', [
            'event_type_id' => $type->id,
            'status' => 'Gepland',
            'start' => now()->format('Y-m-d H:i'),
            'end' => now()->addHour()->format('Y-m-d H:i'),
            'no_service_order' => true,
            'customer_id' => $customer->id,
            'executing_user_ids' => [$admin->id],
        ])->assertCreated();

        $event_id = $create->json('id');

        $this->actingAs($admin)->putJson("/api/events/{$event_id}", [
            'name' => 'Bijgewerkt',
            'eventable_type' => '\\App\\Models\\ServiceOrder',
            'eventable_id' => null,
            'customer_id' => $customer->id,
            'executing_user_ids' => [$admin->id],
        ])->assertOk();

        $this->assertDatabaseHas('events', ['id' => $event_id, 'name' => 'Bijgewerkt']);
        $this->assertDatabaseHas('eventables', [
            'event_id' => $event_id,
            'eventable_type' => 'App\\Models\\Customer',
            'eventable_id' => $customer->id,
        ]);
    }

    public function test_event_without_order_or_flag_is_rejected(): void
    {
        $admin = $this->admin_user();
        $type = EventType::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/events', [
            'event_type_id' => $type->id,
            'status' => 'Gepland',
            'start' => now()->format('Y-m-d H:i'),
            'end' => now()->addHour()->format('Y-m-d H:i'),
            'executing_user_ids' => [$admin->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('eventable_id');
    }
}
