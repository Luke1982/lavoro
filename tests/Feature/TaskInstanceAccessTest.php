<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTaskInstance;
use App\Models\ServiceOrderTaskInstanceCheck;
use App\Models\User;
use App\Models\UserRole;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A task changes only on a werkbon that is still open, and only for someone who
 * gets to see that werkbon and that task. The screen hides the buttons; these
 * hold when the screen is not the one asking.
 */
class TaskInstanceAccessTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function task(?ServiceOrderStage $stage = null): ServiceOrderTaskInstance
    {
        $order = ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'service_order_stage_id' => $stage?->id,
        ]);

        return ServiceOrderTaskInstance::create(['service_order_id' => $order->id, 'description' => 'Airco plaatsen']);
    }

    private function toggle(ServiceOrderTaskInstance $task, User $user)
    {
        return $this->actingAs($user)->patchJson(
            '/serviceordertaskinstances/' . $task->id . '/toggle',
            ['is_complete' => true]
        );
    }

    public function test_a_task_on_a_closed_werkbon_is_refused_with_the_reason(): void
    {
        $task = $this->task(ServiceOrderStage::create(['name' => 'Gesloten', 'order' => 1, 'is_closed_state' => true]));

        $this->toggle($task, $this->userWithPermissions('serviceordertaskinstance.open_close', 'serviceorder.read'))
            ->assertForbidden()
            ->assertJson(['message' => 'Deze werkbon is gesloten. Heropen hem eerst als je de taken nog wilt wijzigen.']);

        $this->assertFalse($task->fresh()->is_complete);
    }

    public function test_cancelling_and_deleting_on_a_closed_werkbon_are_refused(): void
    {
        $task = $this->task(ServiceOrderStage::create(['name' => 'Gesloten', 'order' => 1, 'is_closed_state' => true]));
        $user = $this->userWithPermissions(
            'serviceorder.read',
            'serviceordertaskinstance.cancel',
            'serviceordertaskinstance.delete'
        );

        $this->actingAs($user)
            ->postJson('/serviceordertaskinstances/' . $task->id . '/cancel', ['cancellation_reason' => 'Niet nodig'])
            ->assertForbidden();
        $this->actingAs($user)->deleteJson('/serviceordertaskinstances/' . $task->id)->assertForbidden();

        $this->assertNotNull($task->fresh());
        $this->assertFalse((bool) $task->fresh()->is_cancelled);
    }

    public function test_a_task_on_a_werkbon_you_may_not_see_is_refused(): void
    {
        $task = $this->task();

        $this->toggle($task, $this->userWithPermissions('serviceordertaskinstance.open_close', 'serviceorder.read_own'))
            ->assertForbidden();
    }

    public function test_a_task_on_your_own_werkbon_can_be_completed(): void
    {
        $task = $this->task();
        $user = $this->userWithPermissions('serviceordertaskinstance.open_close', 'serviceorder.read_own');
        $task->serviceOrder->executingUsers()->attach($user->id, ['type' => 'executing']);

        $this->toggle($task, $user)->assertStatus(302);

        $this->assertTrue($task->fresh()->is_complete);
    }

    public function test_a_task_for_a_role_you_do_not_have_here_is_refused(): void
    {
        $task = $this->task();
        $task->userRoles()->attach(UserRole::create(['name' => 'Elektricien', 'color' => '#000000'])->id);

        $this->toggle($task, $this->userWithPermissions('serviceordertaskinstance.open_close', 'serviceorder.read'))
            ->assertForbidden();
    }

    public function test_seeing_every_task_lets_a_role_task_be_completed(): void
    {
        $task = $this->task();
        $task->userRoles()->attach(UserRole::create(['name' => 'Elektricien', 'color' => '#000000'])->id);
        $user = $this->userWithPermissions(
            'serviceordertaskinstance.open_close',
            'serviceorder.read',
            'serviceorder.see_all_task_instances'
        );

        $this->toggle($task, $user)->assertStatus(302);

        $this->assertTrue($task->fresh()->is_complete);
    }

    public function test_a_task_cannot_be_added_to_a_closed_werkbon(): void
    {
        $closed = ServiceOrderStage::create(['name' => 'Gesloten', 'order' => 1, 'is_closed_state' => true]);
        $order = ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'service_order_stage_id' => $closed->id,
        ]);

        $this->actingAs($this->userWithPermissions('serviceordertaskinstance.create', 'serviceorder.read'))
            ->postJson('/serviceordertaskinstances', ['service_order_id' => $order->id, 'description' => 'Extra werk'])
            ->assertForbidden()
            ->assertJson(['message' => 'Deze werkbon is gesloten. Heropen hem eerst als je er nog een taak op wilt zetten.']);

        $this->assertSame(0, ServiceOrderTaskInstance::count());
    }

    public function test_a_task_cannot_be_added_to_a_werkbon_you_may_not_see(): void
    {
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $this->actingAs($this->userWithPermissions('serviceordertaskinstance.create', 'serviceorder.read_own'))
            ->postJson('/serviceordertaskinstances', ['service_order_id' => $order->id, 'description' => 'Extra werk'])
            ->assertForbidden();
    }

    public function test_a_check_on_a_werkbon_you_may_not_see_cannot_be_ticked(): void
    {
        $task = $this->task();
        $check = ServiceOrderTaskInstanceCheck::create([
            'service_order_task_instance_id' => $task->id,
            'title' => 'Rooster schoongemaakt',
            'is_required' => true,
        ]);

        $this->actingAs($this->userWithPermissions('serviceordertaskinstance.open_close', 'serviceorder.read_own'))
            ->patchJson('/api/serviceordertaskinstancechecks/' . $check->id, ['is_done' => true])
            ->assertForbidden();
    }
}
