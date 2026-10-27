<?php

namespace Tests\Feature\Signals;

use App\Domain\Signals\ServiceOrders\ServiceOrderCustomerChanged;
use App\Domain\Signals\ServiceOrders\ServiceOrderInvoiceRecorded;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The other tests dispatch signals by hand, which proves the subscribers work but
 * not that anything still announces. These go through the real HTTP route, so a
 * controller that stops announcing fails here.
 */
class ControllerDispatchTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function order(): ServiceOrder
    {
        ServiceOrderStage::create(['name' => 'Open', 'order' => 1]);

        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'Jansen BV'])->id,
        ]);
    }

    public function test_updating_an_order_customer_announces_it(): void
    {
        Event::fake([ServiceOrderCustomerChanged::class]);

        $order = $this->order();
        $new_customer = Customer::factory()->create(['name' => 'Pietersen NV']);

        $this->actingAs($this->admin())
            ->put(route('serviceorders.update', $order), [
                'customer_id' => $new_customer->id,
            ])
            ->assertRedirect();

        Event::assertDispatched(
            ServiceOrderCustomerChanged::class,
            fn (ServiceOrderCustomerChanged $signal) => $signal->serviceOrder()->is($order)
                && $signal->new_customer_name === 'Pietersen NV'
        );
    }

    public function test_recording_an_invoice_number_through_the_controller_announces_it(): void
    {
        Event::fake([ServiceOrderInvoiceRecorded::class]);

        $order = $this->order();

        $this->actingAs($this->admin())
            ->put(route('serviceorders.update', $order), [
                'customer_id' => $order->customer_id,
                'external_invoice_no' => 'F-2026-009',
            ])
            ->assertRedirect();

        Event::assertDispatched(
            ServiceOrderInvoiceRecorded::class,
            fn (ServiceOrderInvoiceRecorded $signal) => $signal->invoice_number === 'F-2026-009'
        );
    }

    public function test_a_controller_update_leaves_a_trace_with_the_signed_in_user_on_it(): void
    {
        $order = $this->order();
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('serviceorders.update', $order), [
                'customer_id' => $order->customer_id,
                'description' => 'Bijgewerkt via de controller',
            ])
            ->assertRedirect();

        $activity = Activity::where('subject_id', $order->id)
            ->where('event_key', 'serviceorder.updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'the controller update recorded no trace at all');
        $this->assertSame('user', $activity->actor_type);
        $this->assertSame($user->id, $activity->user_id);
    }
}
