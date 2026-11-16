<?php

namespace App\Actions\Tickets;

use App\Domain\Signals\ServiceOrders\TicketAttachedToOrder;
use App\Domain\Signals\Signals;
use App\Models\Ticket;

/**
 * Logs a storing.
 *
 * Thin on purpose, but it exists so the form and the assistant cannot drift:
 * whatever creating a storing comes to mean later, it will mean it in both
 * places. A storing made straight onto a werkbon is attached to it like any
 * other, so the werkbon's timeline and its planning hear of it.
 */
class CreateTicketAction
{
    public function execute(NewTicket $ticket): Ticket
    {
        $created = Ticket::create([
            'asset_id' => $ticket->asset_id,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'created_by_id' => $ticket->created_by_id,
            'service_order_id' => $ticket->service_order_id,
        ]);

        if ($created->serviceOrder) {
            Signals::dispatch(new TicketAttachedToOrder($created->serviceOrder, $created, $created->asset));
        }

        return $created;
    }
}
