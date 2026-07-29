<?php

namespace App\Actions\Tickets;

use App\Models\Ticket;

/**
 * Logs a storing.
 *
 * Thin on purpose — there is genuinely nothing more to it than a row — but it
 * exists so the form and the assistant cannot drift: whatever creating a storing
 * comes to mean later, it will mean it in both places.
 */
class CreateTicketAction
{
    public function execute(NewTicket $ticket): Ticket
    {
        return Ticket::create([
            'asset_id' => $ticket->asset_id,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'created_by_id' => $ticket->created_by_id,
            'service_order_id' => $ticket->service_order_id,
        ]);
    }
}
