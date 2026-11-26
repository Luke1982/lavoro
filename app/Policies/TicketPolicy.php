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
     * The page of one storing: with the right to read storingen, or for whoever
     * works on it. A storing in sight only through a werkbon or machine they read
     * (view) does not open its own page.
     */
    public function viewPage(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.read') || $this->worksOn($user, $ticket);
    }

    /**
     * Planned for the open werkbon the storing is on: they read and edit it there
     * without any right on storingen, by design. Once the werkbon is closed that
     * is over.
     */
    public function worksOn(User $user, Ticket $ticket): bool
    {
        return $ticket->service_order_id !== null
            && ServiceOrder::workedOnBy($user)->whereKey($ticket->service_order_id)->exists();
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

    /** Editing a storing: whoever works on it, or with the right for what the request changes. */
    public function update(User $user, Ticket $ticket, TicketUpdateRequest $request): bool
    {
        return $this->worksOn($user, $ticket)
            || $user->hasPermission('ticket.update')
            || ($request->has('status') && $this->changeStatus($user))
            || ($request->has('priority') && $user->hasPermission('ticket.alter_priority'));
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
     * Hanging a storing this person has in sight on a werkbon; which werkbon is
     * the caller's to check (ServiceOrderPolicy::view).
     */
    public function attachToServiceOrder(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.add_to_serviceorder') && $this->view($user, $ticket);
    }

    public function detachFromServiceOrder(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.detach_from_serviceorder') && $this->view($user, $ticket);
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
