<?php

namespace Tests\Feature\ReadRules;

use App\Models\Customer;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TaskInstanceVisibility;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A task that asks for a role is seen by whoever carries out the werkbon in that
 * role, and the role is the one on the appointment. The rule moved from PHP over
 * loaded relations into ServiceOrderTaskInstance::scopeSeenBy; the screen, the
 * refusal to close and the policy all read it from there, and must agree.
 */
class TaskInstanceRoleRuleTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    private function role(string $name): UserRole
    {
        return UserRole::create(['name' => $name, 'color' => '#000000']);
    }

    private function task(ServiceOrder $order, ?UserRole $role = null): ServiceOrderTaskInstance
    {
        $task = ServiceOrderTaskInstance::create(['service_order_id' => $order->id, 'description' => 'Taak']);

        if ($role) {
            $task->userRoles()->attach($role->id);
        }

        return $task;
    }

    private function appointment(ServiceOrder $order, User $user, UserRole $role): Event
    {
        $event = Event::factory()->create();
        $order->events()->attach($event->id);
        $event->syncExecutingUsers([$user->id], [], [$user->id => [$role->id]]);

        return $event;
    }

    /** @return array<int, int> */
    private function seenThroughEveryDoor(ServiceOrder $order, User $user): array
    {
        $order = $order->fresh(['taskInstances', 'events']);
        $visibility = app(TaskInstanceVisibility::class);

        $listed = $visibility->visibleTo($order, $user)->modelKeys();
        $hidden = $visibility->hiddenFrom($order, $user)->modelKeys();
        $scoped = ServiceOrderTaskInstance::visibleTo($user)->where('service_order_id', $order->id)->pluck('id')->all();

        sort($listed);
        sort($scoped);
        $this->assertSame($listed, $scoped, 'the werkbon screen and the scope disagree');
        $this->assertSame([], array_intersect($listed, $hidden));

        foreach ($order->taskInstances as $task) {
            $seen = in_array($task->id, $listed, true);
            $this->assertSame($seen, $user->can('view', $task), 'the policy disagrees for task ' . $task->id);
        }

        return $listed;
    }

    public function test_a_role_task_is_seen_in_the_role_the_appointment_gives(): void
    {
        $user = $this->userWithPermissions('serviceorder.read');
        $electrician = $this->role('Elektricien');
        $installer = $this->role('Installateur');
        $order = $this->order();

        $open = $this->task($order);
        $theirs = $this->task($order, $electrician);
        $other = $this->task($order, $installer);
        $this->appointment($order, $user, $electrician);

        $this->assertSame([$open->id, $theirs->id], $this->seenThroughEveryDoor($order, $user));
        $this->assertTrue($other->exists);
    }

    public function test_a_role_on_another_werkbon_does_not_count(): void
    {
        $user = $this->userWithPermissions('serviceorder.read');
        $electrician = $this->role('Elektricien');
        $here = $this->order();
        $elsewhere = $this->order();

        $this->appointment($elsewhere, $user, $electrician);
        $this->task($here, $electrician);

        $this->assertSame([], $this->seenThroughEveryDoor($here, $user));
    }

    public function test_a_cancelled_or_deleted_appointment_gives_no_role(): void
    {
        $user = $this->userWithPermissions('serviceorder.read');
        $electrician = $this->role('Elektricien');
        $order = $this->order();
        $task = $this->task($order, $electrician);
        $event = $this->appointment($order, $user, $electrician);

        $this->assertSame([$task->id], $this->seenThroughEveryDoor($order, $user));

        $event->update(['status' => 'Geannuleerd']);
        $this->assertSame([], $this->seenThroughEveryDoor($order, $user));

        $event->delete();
        $this->assertSame([], $this->seenThroughEveryDoor($order, $user));
    }

    public function test_seeing_every_task_needs_no_role(): void
    {
        $user = $this->userWithPermissions('serviceorder.read', 'serviceorder.see_all_task_instances');
        $order = $this->order();
        $task = $this->task($order, $this->role('Elektricien'));

        $this->assertSame([$task->id], $this->seenThroughEveryDoor($order, $user));
    }

    public function test_a_task_on_a_werkbon_out_of_sight_is_out_of_the_scope(): void
    {
        $user = $this->userWithPermissions('serviceorder.read_own');
        $order = $this->order();
        $task = $this->task($order);

        $this->assertFalse(ServiceOrderTaskInstance::visibleTo($user)->whereKey($task->id)->exists());
        $this->assertFalse($user->can('view', $task));
    }
}
