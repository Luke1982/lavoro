<?php

namespace App\Policies;

use App\Http\Requests\TicketUpdateRequest;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Determine if the user can update the ticket with given changes.
     *
     * @param  Ticket  $ticket
     * @param  array  $changes
     */
    /**
     * Whether this person may log a storing.
     *
     * The permission has always existed; the create form never asked. Anything
     * new goes through here, so at least the assistant cannot be the loosest door
     * in the building.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('ticket.create');
    }

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

    /**
     * Determine whether the user can attach a ticket to a service order.
     */
    public function attachToServiceOrder(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.add_to_serviceorder');
    }
}
