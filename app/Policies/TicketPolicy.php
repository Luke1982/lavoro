<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Ticket;
use App\Http\Requests\TicketUpdateRequest;

class TicketPolicy
{
    /**
     * Determine if the user can update the ticket with given changes.
     *
     * @param User $user
     * @param Ticket $ticket
     * @param array $changes
     * @return bool
     */
    public function update(User $user, Ticket $ticket, TicketUpdateRequest $request): bool
    {
        if ($request->has('status')) {
            if ($user->hasPermission('ticket.change_status')) {
                return true;
            }
        }
        if ($request->has('priority')) {
            if ($user->hasPermission('ticket.alter_priority')) {
                return true;
            }
        }
        return $user->hasPermission('ticket.update');
    }
}
