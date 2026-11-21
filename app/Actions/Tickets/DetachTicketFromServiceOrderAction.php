<?php

namespace App\Actions\Tickets;

use App\Domain\Signals\ServiceOrders\TicketDetachedFromOrder;
use App\Domain\Signals\Signals;
use App\Models\ServiceOrder;
use App\Models\Ticket;

/**
 * Takes a storing off its werkbon again: from the werkbon screen, and when a
 * paste off the bakje is undone.
 */
class DetachTicketFromServiceOrderAction
{
    public function execute(ServiceOrder $service_order, Ticket $ticket): void
    {
        $ticket->update(['service_order_id' => null]);

        $asset = $ticket->asset()->with(['product.brand', 'product.productType'])->first();

        Signals::dispatch(new TicketDetachedFromOrder($service_order, $ticket, $asset));
    }
}
