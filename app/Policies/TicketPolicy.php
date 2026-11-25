<?php

namespace App\Policies;

use App\Http\Requests\TicketUpdateRequest;
use App\Models\Asset;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Whether storingen can be in sight at all: every one with the right to read
     * storingen or to see them all on the overview, otherwise those on a werkbon
     * or machine this person may read. Which ones is Ticket::scopeVisibleTo's
     * business.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('ticket.read')
            || $user->hasPermission('ticket.see_all')
            || $user->can('viewAny', ServiceOrder::class)
            || $user->can('viewAny', Asset::class);
    }

    /**
     * The storingen overview and its map: a right of their own, and stricter than
     * reading. They list every storing, not only the ones in sight.
     */
    public function list(User $user): bool
    {
        return $user->hasPermission('ticket.see_all');
    }

    /**
     * The page of one storing: only with the right to read storingen, which puts
     * every storing in sight. A storing in sight through a werkbon or machine
     * (view) does not open its own page.
     */
    public function viewPage(User $user): bool
    {
        return $user->hasPermission('ticket.read');
    }

    /**
     * A storing is in sight with the right to read storingen, or through a
     * werkbon or machine this person has in sight.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        return Ticket::visibleTo($user)->whereKey($ticket->id)->exists();
    }

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
        if ($request->has('status') && $this->changeStatus($user)) {
            return true;
        }
        if ($request->has('priority')) {
            if ($user->hasPermission('ticket.alter_priority')) {
                return true;
            }
        }

        return $user->hasPermission('ticket.update');
    }

    /** A storing's status alone, one storing or many at once. */
    public function changeStatus(User $user): bool
    {
        return $user->hasPermission('ticket.change_status');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('ticket.delete');
    }

    /**
     * Of deze persoon de klant om aanvullende informatie mag vragen.
     *
     * Een eigen recht en niet dat van bijwerken: hier gaat een mail uit op naam
     * van het bedrijf, met een link erin waarmee iemand van buiten bestanden aan
     * de storing kan hangen.
     */
    public function requestCustomerInfo(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.request_customer_info');
    }

    /**
     * Determine whether the user can attach a ticket to a service order.
     */
    public function attachToServiceOrder(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.add_to_serviceorder');
    }

    public function detachFromServiceOrder(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.detach_from_serviceorder');
    }

    /**
     * Hanging a storing off another machine: the one it was reported on was the
     * wrong one. An ordinary edit of the storing, so the ordinary right.
     */
    public function moveToAsset(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.update');
    }
}
