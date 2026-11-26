<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Hanging a storing on a werkbon, and taking it off again.
 *
 * Both of these dispatched their signal with a sentence where the signal wanted
 * the storing itself, so both threw a TypeError the moment they ran — on the
 * screen, not only through a tool. Nothing covered either path.
 */
class ServiceOrderTicketAttachmentTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private function ticketOn(ServiceOrder $order, ?int $service_order_id = null): Ticket
    {
        Product::factory()->create();

        return Ticket::factory()->create([
            'asset_id' => Asset::factory()->create(['customer_id' => $order->customer_id])->id,
            'service_order_id' => $service_order_id,
        ]);
    }

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
    }

    public function test_attaching_a_storing_to_a_werkbon_works(): void
    {
        $order = $this->order();
        $ticket = $this->ticketOn($order);

        $this->actingAs($this->userWithPermissions('ticket.add_to_serviceorder', 'serviceorder.read', 'ticket.read'))
            ->post('/serviceorders/' . $order->id . '/tickets/' . $ticket->id)
            ->assertRedirect();

        $this->assertSame($order->id, $ticket->fresh()->service_order_id);
    }

    public function test_detaching_a_storing_from_a_werkbon_works(): void
    {
        $order = $this->order();
        $ticket = $this->ticketOn($order, $order->id);

        $this->actingAs($this->userWithPermissions('ticket.detach_from_serviceorder', 'serviceorder.read'))
            ->delete('/serviceorders/' . $order->id . '/tickets/' . $ticket->id)
            ->assertRedirect();

        $this->assertNull($ticket->fresh()->service_order_id);
    }

    public function test_a_storing_out_of_sight_is_not_hung_on_a_werkbon(): void
    {
        $order = $this->order();
        $ticket = $this->ticketOn($order);

        $this->actingAs($this->userWithPermissions('ticket.add_to_serviceorder', 'serviceorder.read'))
            ->postJson('/serviceorders/' . $order->id . '/tickets/' . $ticket->id)
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->service_order_id);
    }

    public function test_a_storing_is_not_hung_on_a_werkbon_out_of_sight(): void
    {
        $order = $this->order();
        $ticket = $this->ticketOn($order);

        $this->actingAs($this->userWithPermissions('ticket.add_to_serviceorder', 'ticket.read'))
            ->postJson('/serviceorders/' . $order->id . '/tickets/' . $ticket->id)
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->service_order_id);
    }

    public function test_detaching_takes_a_delete_not_a_link(): void
    {
        $order = $this->order();
        $ticket = $this->ticketOn($order, $order->id);

        $this->actingAs($this->userWithPermissions('ticket.detach_from_serviceorder', 'serviceorder.read'))
            ->get('/serviceorders/' . $order->id . '/tickets/' . $ticket->id . '/detach');

        $this->assertSame($order->id, $ticket->fresh()->service_order_id);
    }
}
