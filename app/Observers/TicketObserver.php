<?php

namespace App\Observers;

use App\Models\Ticket;
use Illuminate\Support\Facades\Auth;

class TicketObserver
{
    public function updated(Ticket $ticket): void
    {
        $changes = $ticket->getChanges();

        if (array_key_exists('status', $changes)) {
            $old_status = $ticket->getOriginal('status');
            $new_status = $changes['status'];

            $ticket->logActivity("Status gewijzigd van '{$old_status}' naar '{$new_status}'", category: 'status');

            if ($new_status === 'Gesloten') {
                $ticket->closed_by_id = Auth::id();
                $ticket->closed_on    = now();
                $ticket->saveQuietly();
            } elseif ($old_status === 'Gesloten') {
                $ticket->closed_by_id = null;
                $ticket->closed_on    = null;
                $ticket->saveQuietly();
            }
        }

        if (array_key_exists('priority', $changes)) {
            $old_priority = $ticket->getOriginal('priority');
            $new_priority = $changes['priority'];
            $ticket->logActivity(
                "Prioriteit gewijzigd van '{$old_priority}' naar '{$new_priority}'",
                category: 'status'
            );
        }
    }
}
