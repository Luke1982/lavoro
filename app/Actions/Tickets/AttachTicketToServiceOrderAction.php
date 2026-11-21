<?php

namespace App\Actions\Tickets;

use App\Domain\Signals\ServiceOrders\TicketAttachedToOrder;
use App\Domain\Signals\ServiceOrders\TicketDetachedFromOrder;
use App\Domain\Signals\Signals;
use App\Models\ServiceOrder;
use App\Models\Ticket;

/**
 * Hangs a storing off a werkbon, the same way from the werkbon screen and from
 * the bakje, so both leave the same line on the werkbon's timeline.
 *
 * A storing that was on another werkbon leaves that one first, and it is told so:
 * its timeline says the storing went, and a storing that was in behandeling there
 * goes back to open before this werkbon's planning decides about it again.
 */
class AttachTicketToServiceOrderAction
{
    public function execute(ServiceOrder $service_order, Ticket $ticket): void
    {
        $previous_order = $ticket->service_order_id !== null && (int) $ticket->service_order_id !== $service_order->id
            ? ServiceOrder::find($ticket->service_order_id)
            : null;

        $ticket->update(['service_order_id' => $service_order->id]);

        $asset = $ticket->asset()->with(['product.brand', 'product.productType'])->first();

        if ($previous_order) {
            Signals::dispatch(new TicketDetachedFromOrder($previous_order, $ticket, $asset));
        }

        Signals::dispatch(new TicketAttachedToOrder($service_order, $ticket, $asset));
    }
}
