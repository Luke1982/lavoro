<?php

namespace Tests\Feature;

use App\Jobs\BulkMoveServiceOrderStageJob;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTaskInstance;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Moving werkbonnen together holds each one to the same rules as the stepper on
 * the werkbon itself. The ones that pass move, the others stay and are named.
 */
class ServiceOrderBulkStageTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private ServiceOrderStage $open;

    private ServiceOrderStage $closed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->open = ServiceOrderStage::create(['name' => 'Open', 'order' => 1]);
        $this->closed = ServiceOrderStage::create(['name' => 'Gesloten', 'order' => 2, 'is_closed_state' => true]);
    }

    private function order(array $attributes = []): ServiceOrder
    {
        return ServiceOrder::factory()->create(array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'service_order_stage_id' => $this->open->id,
            'signed_by' => 'Jan Klant',
            'signature_base64' => 'data:image/png;base64,abc',
        ], $attributes));
    }

    /** @return array{0: ServiceOrder, 1: ServiceOrder, 2: ServiceOrder} */
    private function readyUnsignedAndWithOpenTask(): array
    {
        $with_open_task = $this->order();
        ServiceOrderTaskInstance::create(['service_order_id' => $with_open_task->id, 'description' => 'Airco plaatsen']);

        return [$this->order(), $this->order(['signed_by' => null, 'signature_base64' => null]), $with_open_task];
    }

    public function test_closing_together_moves_only_the_werkbonnen_that_may_close(): void
    {
        [$ready, $unsigned, $with_open_task] = $this->readyUnsignedAndWithOpenTask();

        $response = $this->actingAs($this->userWithPermissions('serviceorder.update', 'serviceorder.read'))
            ->post(route('serviceorders.bulk-update'), [
                'service_order_ids' => [$ready->id, $unsigned->id, $with_open_task->id],
                'service_order_stage_id' => $this->closed->id,
            ]);

        $this->assertSame($this->closed->id, $ready->fresh()->service_order_stage_id);
        $this->assertSame($this->open->id, $unsigned->fresh()->service_order_stage_id);
        $this->assertSame($this->open->id, $with_open_task->fresh()->service_order_stage_id);

        $flash = session('error');
        $this->assertStringContainsString($unsigned->number . ' (niet ondertekend)', $flash);
        $this->assertStringContainsString($with_open_task->number . ' (nog 1 open taak)', $flash);
        $this->assertStringNotContainsString($ready->number, $flash);
    }

    public function test_the_queued_move_holds_each_werkbon_to_the_same_rules(): void
    {
        [$ready, $unsigned, $with_open_task] = $this->readyUnsignedAndWithOpenTask();
        $actor = $this->userWithPermissions('serviceorder.update', 'serviceorder.read');

        (new BulkMoveServiceOrderStageJob([$ready->id, $unsigned->id, $with_open_task->id], $this->closed->id, $actor->id))
            ->handle();

        $this->assertSame($this->closed->id, $ready->fresh()->service_order_stage_id);
        $this->assertSame($this->open->id, $unsigned->fresh()->service_order_stage_id);
        $this->assertSame($this->open->id, $with_open_task->fresh()->service_order_stage_id);
    }

    public function test_a_closed_werkbon_is_not_reopened_together_without_the_permission(): void
    {
        $closed = $this->order(['service_order_stage_id' => $this->closed->id]);

        $this->actingAs($this->userWithPermissions('serviceorder.update', 'serviceorder.read'))
            ->post(route('serviceorders.bulk-update'), [
                'service_order_ids' => [$closed->id],
                'service_order_stage_id' => $this->open->id,
            ]);

        $this->assertSame($this->closed->id, $closed->fresh()->service_order_stage_id);
        $this->assertStringContainsString($closed->number . ' (gesloten, en heropenen mag jij niet)', session('error'));
    }

    public function test_the_queued_move_without_anyone_behind_it_moves_nothing(): void
    {
        $ready = $this->order();

        (new BulkMoveServiceOrderStageJob([$ready->id], $this->closed->id))->handle();

        $this->assertSame($this->open->id, $ready->fresh()->service_order_stage_id);
    }
}
