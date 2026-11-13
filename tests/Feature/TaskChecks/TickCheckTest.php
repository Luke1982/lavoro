<?php

namespace Tests\Feature\TaskChecks;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\ServiceOrderTaskInstanceCheck;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Ticking a check records who and when, and puts the fact on the werkbon's
 * timeline; it is refused once the task is done, and needs the right to work
 * the task.
 */
class TickCheckTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function check(array $task = []): ServiceOrderTaskInstanceCheck
    {
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $instance = ServiceOrderTaskInstance::create([
            'service_order_id' => $order->id,
            'description' => 'Airco plaatsen',
        ] + $task);

        return ServiceOrderTaskInstanceCheck::create([
            'service_order_task_instance_id' => $instance->id,
            'title' => 'Rooster schoongemaakt',
            'is_required' => true,
        ]);
    }

    private function tick(ServiceOrderTaskInstanceCheck $check, bool $is_done = true, ?array $permissions = null)
    {
        $user = $this->userWithPermissions(...($permissions ?? ['serviceordertaskinstance.open_close']));

        return $this->actingAs($user)->patchJson(
            "/api/serviceordertaskinstancechecks/{$check->id}",
            ['is_done' => $is_done]
        );
    }

    public function test_ticking_records_who_and_when_and_writes_the_timeline(): void
    {
        $check = $this->check();

        $response = $this->tick($check)->assertOk();

        $fresh = $check->fresh();
        $this->assertTrue($fresh->is_done);
        $this->assertNotNull($fresh->checked_at);
        $this->assertSame(auth()->id(), $fresh->checked_by);
        $response->assertJsonPath('is_done', true)->assertJsonPath('checked_by.id', auth()->id());

        $order_id = $check->instance->service_order_id;
        $this->assertTrue(Activity::where('subject_type', ServiceOrder::class)
            ->where('subject_id', $order_id)
            ->where('description', 'Check "Rooster schoongemaakt" afgevinkt bij taak "Airco plaatsen"')
            ->exists());
    }

    public function test_unticking_clears_who_and_when(): void
    {
        $check = $this->check();
        $this->tick($check)->assertOk();

        $this->tick($check, false)->assertOk();

        $fresh = $check->fresh();
        $this->assertFalse($fresh->is_done);
        $this->assertNull($fresh->checked_at);
        $this->assertNull($fresh->checked_by);
    }

    public function test_ticking_on_a_completed_task_is_refused(): void
    {
        $check = $this->check(['is_complete' => true]);

        $this->tick($check)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Deze taak is al voltooid; heropen hem om checks te wijzigen.');

        $this->assertFalse($check->fresh()->is_done);
    }

    public function test_ticking_needs_permission(): void
    {
        $check = $this->check();

        $this->tick($check, true, ['serviceordertaskinstance.read'])->assertForbidden();

        $this->assertFalse($check->fresh()->is_done);
    }
}
