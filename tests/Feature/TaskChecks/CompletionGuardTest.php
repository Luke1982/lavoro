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
 * A task completes only once every required check on it is ticked; optional
 * checks never hold it back.
 */
class CompletionGuardTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function task(bool $is_required): ServiceOrderTaskInstance
    {
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $instance = ServiceOrderTaskInstance::create([
            'service_order_id' => $order->id,
            'description' => 'Airco plaatsen',
        ]);

        ServiceOrderTaskInstanceCheck::create([
            'service_order_task_instance_id' => $instance->id,
            'title' => 'Rooster schoongemaakt',
            'is_required' => $is_required,
        ]);

        return $instance;
    }

    private function complete(ServiceOrderTaskInstance $instance)
    {
        return $this->actingAs($this->userWithPermissions('serviceordertaskinstance.open_close'))
            ->patch("/serviceordertaskinstances/{$instance->id}/toggle", ['is_complete' => true]);
    }

    public function test_completing_is_refused_while_a_required_check_is_open(): void
    {
        $instance = $this->task(true);

        $this->complete($instance)->assertSessionHasErrors(['task' => 'Vink eerst alle verplichte checks af.']);

        $this->assertFalse($instance->fresh()->is_complete);
    }

    public function test_a_ticked_required_check_lets_the_task_complete(): void
    {
        $instance = $this->task(true);
        $instance->checks()->update(['is_done' => true]);

        $this->complete($instance)->assertSessionHasNoErrors();

        $this->assertTrue($instance->fresh()->is_complete);
    }

    public function test_open_optional_checks_do_not_block_completion(): void
    {
        $instance = $this->task(false);

        $this->complete($instance)->assertSessionHasNoErrors();

        $this->assertTrue($instance->fresh()->is_complete);
    }

    public function test_a_typed_task_is_named_by_its_description_on_the_timeline(): void
    {
        $instance = $this->task(false);

        $this->complete($instance)->assertSessionHasNoErrors();

        $this->assertTrue(Activity::where('description', 'Taak "Airco plaatsen" afgerond')->exists());
    }
}
