<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventFeedbackPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_permission_can_provide_feedback(): void
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'planner']);
        $permission = Permission::firstOrCreate(['name' => 'event.provide_feedback'], ['label' => 'x']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $event = Event::factory()->create();

        $this->assertTrue($user->can('provideFeedback', $event));
    }

    public function test_user_without_permission_cannot_provide_feedback(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $this->assertFalse($user->can('provideFeedback', $event));
    }

    public function test_event_has_remarks_and_images_relations(): void
    {
        $event = Event::factory()->create();

        $this->assertCount(0, $event->remarks);
        $this->assertCount(0, $event->images);
    }

    public function test_feedback_denied_when_event_has_service_order(): void
    {
        $user = \App\Models\User::factory()->create();
        $role = \App\Models\Role::firstOrCreate(['name' => 'planner']);
        $permission = \App\Models\Permission::firstOrCreate(['name' => 'event.provide_feedback'], ['label' => 'x']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $event = \App\Models\Event::factory()->create();
        $customer = \App\Models\Customer::factory()->create();
        $order = \App\Models\ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $event->serviceOrders()->attach($order->id);

        $this->assertFalse($user->can('provideFeedback', $event->fresh()));
    }
}
