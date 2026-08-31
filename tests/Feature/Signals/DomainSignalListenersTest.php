<?php

namespace Tests\Feature\Signals;

use App\Domain\Signals\ServiceOrders\ServiceOrderAssigned;
use App\Domain\Signals\ServiceOrders\ServiceOrderCustomerChanged;
use App\Domain\Signals\ServiceOrders\ServiceOrderInvoiceRecorded;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\MaintenanceContract;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;
use App\Notifications\NewServiceOrderAssigned;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Covers the point of the event layer: announcing a fact makes the subscribers
 * act, without the announcer knowing they exist.
 */
class DomainSignalListenersTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'Jansen BV'])->id,
        ]);
    }

    /**
     * A user with no push token resolves to no channels at all, so a notification
     * to them is never sent. Anything asserting on notifications needs one.
     */
    private function notifiableUser(): User
    {
        $user = User::factory()->create();
        DeviceToken::create([
            'user_id' => $user->id,
            'token' => 'test-token-' . $user->id,
            'platform' => 'android',
        ]);

        return $user;
    }

    public function test_recording_an_invoice_number_moves_the_order_to_the_invoiced_stage(): void
    {
        ServiceOrderStage::create(['name' => 'Open', 'order' => 1]);
        $invoiced = ServiceOrderStage::create([
            'name' => 'Gefactureerd',
            'order' => 9,
            'is_invoiced_state' => true,
        ]);

        $order = $this->order();
        $this->assertNotSame($invoiced->id, $order->service_order_stage_id);

        event(new ServiceOrderInvoiceRecorded($order, 'F-2026-001'));

        $this->assertSame($invoiced->id, $order->fresh()->service_order_stage_id);
    }

    public function test_reassigning_an_order_to_another_customer_breaks_its_contract_link(): void
    {
        $order = $this->order();
        $contract = MaintenanceContract::create([
            'customer_id' => $order->customer_id,
            'title' => 'Onderhoud 2026',
            'start_date' => now()->toDateString(),
            'price' => 100,
            'price_interval' => 'Jaarlijks',
            'frequency' => 'Jaarlijks',
        ]);
        $order->update(['maintenance_contract_id' => $contract->id]);

        event(new ServiceOrderCustomerChanged(
            $order,
            $order->customer_id,
            'Jansen BV',
            'Pietersen NV',
            'Onderhoud 2026',
        ));

        $this->assertNull($order->fresh()->maintenance_contract_id);
        $this->assertTrue(
            Activity::where('subject_id', $order->id)
                ->where('description', 'like', '%Losgekoppeld van contract%')
                ->exists()
        );
    }

    public function test_assigning_users_notifies_only_the_newly_assigned_ones(): void
    {
        Notification::fake();

        $order = $this->order();
        $newcomer = $this->notifiableUser();

        event(new ServiceOrderAssigned($order, [$newcomer->id]));

        Notification::assertSentTo($newcomer, NewServiceOrderAssigned::class);
    }

    public function test_assigning_nobody_new_notifies_nobody(): void
    {
        Notification::fake();

        event(new ServiceOrderAssigned($this->order(), []));

        Notification::assertNothingSent();
    }

    public function test_a_stage_move_stamps_the_closing_date_and_clears_it_again(): void
    {
        $open = ServiceOrderStage::create(['name' => 'Open', 'order' => 1]);
        $closed = ServiceOrderStage::create([
            'name' => 'Gesloten',
            'order' => 2,
            'is_closed_state' => true,
        ]);

        $order = $this->order();
        $order->forceFill(['closed_on' => null])->save();

        $order->moveToStage($open);
        $this->assertNull($order->fresh()->closed_on);

        $order->moveToStage($closed);
        $this->assertNotNull($order->fresh()->closed_on);

        $order->moveToStage($open);
        $this->assertNull($order->fresh()->closed_on);
    }

    public function test_moving_to_the_stage_it_is_already_in_changes_and_records_nothing(): void
    {
        $stage = ServiceOrderStage::create(['name' => 'Open', 'order' => 1]);
        $order = $this->order();
        $order->moveToStage($stage);

        $before = Activity::where('event_key', 'serviceorder.stage_changed')->count();

        $this->assertFalse($order->moveToStage($stage));
        $this->assertSame($before, Activity::where('event_key', 'serviceorder.stage_changed')->count());
    }

    public function test_a_stage_move_records_the_stage_before_and_after(): void
    {
        $open = ServiceOrderStage::create(['name' => 'Open', 'order' => 1]);
        $doing = ServiceOrderStage::create(['name' => 'In uitvoering', 'order' => 2]);

        $order = $this->order();
        $order->moveToStage($open);
        $order->moveToStage($doing);

        $activity = Activity::where('event_key', 'serviceorder.stage_changed')
            ->latest('id')->first();

        $this->assertSame('stage', $activity->category);
        $this->assertStringContainsString('In uitvoering', $activity->description);
        $this->assertSame($open->id, $activity->metadata['previous_stage_id']);
        $this->assertSame($doing->id, $activity->metadata['new_stage_id']);
    }
}
