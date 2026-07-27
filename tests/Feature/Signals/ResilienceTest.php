<?php

namespace Tests\Feature\Signals;

use App\Jobs\BulkMoveServiceOrderStageJob;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A broken audit trail must not break the work it describes, and a large bulk
 * move must not block the request that asked for it.
 */
class ResilienceTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function orders(int $count): array
    {
        ServiceOrderStage::firstOrCreate(['name' => 'Open'], ['order' => 1]);
        $customer = Customer::factory()->create();

        return ServiceOrder::factory()->count($count)
            ->create(['customer_id' => $customer->id])
            ->pluck('id')->all();
    }

    public function test_a_failure_while_logging_does_not_fail_the_save(): void
    {
        Log::spy();

        $order = ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);

        /** A column that cannot hold what we are about to give it. */
        DB::statement('DROP TABLE activity_changes');

        $order->update(['external_invoice_no' => 'F-STILL-SAVES']);

        $this->assertSame('F-STILL-SAVES', $order->fresh()->external_invoice_no);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_small_bulk_move_runs_inline(): void
    {
        Queue::fake();

        $ids = $this->orders(3);
        $stage = ServiceOrderStage::create(['name' => 'Gereed', 'order' => 3]);

        $this->actingAs($this->admin())->post(route('serviceorders.bulk-update'), [
            'service_order_ids' => $ids,
            'service_order_stage_id' => $stage->id,
        ]);

        Queue::assertNotPushed(BulkMoveServiceOrderStageJob::class);
        $this->assertSame(
            3,
            ServiceOrder::whereIn('id', $ids)->where('service_order_stage_id', $stage->id)->count()
        );
    }

    public function test_a_large_bulk_move_goes_to_the_queue_and_says_so(): void
    {
        Queue::fake();

        $ids = $this->orders(41);
        $stage = ServiceOrderStage::create(['name' => 'Gereed', 'order' => 3]);

        $this->actingAs($this->admin())
            ->post(route('serviceorders.bulk-update'), [
                'service_order_ids' => $ids,
                'service_order_stage_id' => $stage->id,
            ])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'achtergrond'));

        Queue::assertPushed(
            BulkMoveServiceOrderStageJob::class,
            fn (BulkMoveServiceOrderStageJob $job) => count($job->service_order_ids) === 41
                && $job->service_order_stage_id === $stage->id
        );
    }

    public function test_the_queued_bulk_move_still_records_who_asked_for_it(): void
    {
        $ids = $this->orders(2);
        $stage = ServiceOrderStage::create(['name' => 'Gereed', 'order' => 3]);
        $actor = $this->admin();

        (new BulkMoveServiceOrderStageJob($ids, $stage->id, $actor->id))->handle();

        $activity = Activity::where('event_key', 'serviceorder.stage_changed')
            ->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('user', $activity->actor_type);
        $this->assertSame($actor->id, $activity->user_id);
    }
}
