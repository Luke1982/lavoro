<?php

namespace Tests\Feature\Signals;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Covers the automatic audit trail: what a change records, how repeat saves in one
 * request collapse, and who gets the blame.
 */
class ModelHistoryTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'Jansen BV'])->id,
        ]);
    }

    private function updates(): Collection
    {
        return Activity::with('fieldChanges')->where('event_key', 'serviceorder.updated')->get();
    }

    public function test_a_change_records_both_the_raw_value_and_a_readable_label(): void
    {
        $order = $this->order();

        $order->update(['external_invoice_no' => 'F-2026-001']);

        $activity = $this->updates()->sole();
        $change = $activity->fieldChanges->sole();

        $this->assertSame('serviceorder.updated', $activity->event_key);
        $this->assertSame(ServiceOrder::class, $activity->subject_type);
        $this->assertSame($order->id, $activity->subject_id);

        $this->assertSame('external_invoice_no', $change->field);
        $this->assertSame('Extern factuurnummer', $change->label);
        $this->assertNull($change->old_value);
        $this->assertSame('F-2026-001', $change->new_value);
    }

    public function test_a_foreign_key_records_the_id_and_the_name_it_points_at(): void
    {
        $order = $this->order();
        $project = Project::create([
            'title' => 'Nieuwbouw Noord',
            'customer_id' => $order->customer_id,
            'project_manager_id' => $this->admin()->id,
        ]);

        $order->update(['project_id' => $project->id]);

        $change = $this->updates()->sole()->fieldChanges->sole();

        $this->assertSame('Project', $change->label);
        $this->assertSame((string) $project->id, $change->new_value);
        $this->assertSame('Nieuwbouw Noord', $change->new_label);
    }

    public function test_repeat_saves_in_one_request_collapse_into_a_single_entry(): void
    {
        $order = $this->order();

        $order->update(['external_invoice_no' => 'F-1']);
        $order->update(['external_invoice_no' => 'F-2']);
        $order->update(['description' => 'Nieuwe omschrijving']);

        $activity = $this->updates()->sole();

        $this->assertCount(2, $activity->fieldChanges);
    }

    public function test_a_field_that_moves_twice_keeps_its_original_starting_value(): void
    {
        $order = $this->order();

        $order->update(['external_invoice_no' => 'F-1']);
        $order->update(['external_invoice_no' => 'F-2']);

        $change = $this->updates()->sole()->fieldChanges
            ->firstWhere('field', 'external_invoice_no');

        $this->assertNull($change->old_value);
        $this->assertSame('F-2', $change->new_value);
    }

    public function test_a_change_with_no_signed_in_user_is_recorded_as_the_system(): void
    {
        $this->order()->update(['external_invoice_no' => 'F-1']);

        $activity = $this->updates()->sole();

        $this->assertSame('system', $activity->actor_type);
        $this->assertNull($activity->user_id);
    }

    public function test_a_change_by_a_signed_in_user_records_that_user_by_name(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $this->order()->update(['external_invoice_no' => 'F-1']);

        $activity = $this->updates()->sole();

        $this->assertSame('user', $activity->actor_type);
        $this->assertSame($user->id, $activity->user_id);
        $this->assertSame($user->name, $activity->actor_name);
    }

    public function test_ignored_fields_are_never_recorded(): void
    {
        $order = $this->order();

        $order->update(['signature_base64' => 'data:image/png;base64,AAAA']);

        $this->assertCount(0, $this->updates());
    }

    public function test_a_save_that_changes_nothing_records_nothing(): void
    {
        $order = $this->order();
        $before = $this->updates()->count();

        $order->update(['description' => $order->description]);

        $this->assertCount($before, $this->updates());
    }

    public function test_work_rolled_back_leaves_no_trace(): void
    {
        $order = $this->order();

        try {
            DB::transaction(function () use ($order) {
                $order->update(['external_invoice_no' => 'F-ROLLED-BACK']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertCount(0, $this->updates());
    }
}
