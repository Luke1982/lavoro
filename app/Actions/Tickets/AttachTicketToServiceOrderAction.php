<?php

namespace App\Actions\Tickets;

use App\Domain\Signals\ServiceOrders\TicketAttachedToOrder;
use App\Domain\Signals\Signals;
use App\Models\ServiceOrder;
use App\Models\Ticket;

/**
 * Hangs a storing off a werkbon, the same way from the werkbon screen and from
 * the bakje, so both leave the same line on the werkbon's timeline.
 */
class AttachTicketToServiceOrderAction
{
    public function execute(ServiceOrder $service_order, Ticket $ticket): void
    {
        $ticket->update(['service_order_id' => $service_order->id]);

        $asset = $ticket->asset()->with(['product.brand', 'product.productType'])->first();

        if ($asset) {
            Signals::dispatch(new TicketAttachedToOrder($service_order, $ticket, $asset));
        }
    }
}
