<?php

namespace App\Domain\Tray\Paste;

use App\Actions\Tickets\AttachTicketToServiceOrderAction;
use App\Actions\Tickets\DetachTicketFromServiceOrderAction;
use App\Domain\Tray\Kinds\TicketKind;
use App\Exceptions\Refusal;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Storingen dropped on a werkbon: they are hung off it.
 *
 * Only linking, so it happens at once and can be undone. The exception is a
 * storing that is already on another werkbon: moving it takes it away from
 * there, and that is asked first.
 */
class TicketsOntoServiceOrder implements UndoablePasteHandler
{
    public function question(Paste $paste): ?Question
    {
        $order = DropTarget::openServiceOrder($paste);
        $moving = $this->moving($this->toAttach($paste, $order));

        if ($moving->isEmpty()) {
            return null;
        }

        $one = $moving->count() === 1;

        return new Question(
            title: TicketKind::heading($moving->count()) . ' verplaatsen?',
            subtitle: $one
                ? 'Deze storing staat nu op werkbon ' . $moving->first()->serviceOrder?->number . '.'
                : 'Deze storingen staan nu op een andere werkbon.',
            items: TicketKind::listed($moving),
            choices: [new Choice(
                value: 'move',
                label: 'Verplaatsen naar werkbon ' . $order->number,
                description: $one ? 'Hij gaat van zijn huidige werkbon af.' : 'Ze gaan van hun huidige werkbon af.',
                confirm: 'Verplaatsen',
            )],
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $order = DropTarget::openServiceOrder($paste);
        $tickets = $this->toAttach($paste, $order);
        $moved = $this->moving($tickets)->isNotEmpty();
        $gate = Gate::forUser($paste->user);

        $tickets->each(function (Ticket $ticket) use ($gate, $order) {
            $gate->authorize('attachToServiceOrder', $ticket);
            app(AttachTicketToServiceOrderAction::class)->execute($order, $ticket);
        });

        $message = $tickets->count() === 1
            ? '"' . TicketKind::title($tickets->first()) . '" staat nu op werkbon ' . $order->number . '.'
            : $tickets->count() . ' storingen staan nu op werkbon ' . $order->number . '.';

        return new PasteResult($message, undo: $moved ? null : [
            'service_order_id' => $order->id,
            'ticket_ids' => $tickets->pluck('id')->all(),
        ]);
    }

    /**
     * Not offered after a move: taking the storing off would not put it back on
     * the werkbon it came from.
     */
    public function undo(User $user, array $undo): string
    {
        $order = ServiceOrder::findOrFail($undo['service_order_id']);
        $gate = Gate::forUser($user);

        $tickets = Ticket::visibleTo($user)
            ->whereIn('id', $undo['ticket_ids'])
            ->where('service_order_id', $order->id)
            ->get();

        $tickets->each(function (Ticket $ticket) use ($gate, $order) {
            $gate->authorize('detachFromServiceOrder', $ticket);
            app(DetachTicketFromServiceOrderAction::class)->execute($order, $ticket);
        });

        return ($tickets->count() === 1 ? 'De storing is' : 'De storingen zijn')
            . ' weer van werkbon ' . $order->number . ' af.';
    }

    /**
     * The storingen that are not on this werkbon yet, each checked against its
     * customer: a storing on another customer's machine does not belong here.
     *
     * @return Collection<int, Ticket>
     */
    private function toAttach(Paste $paste, ServiceOrder $order): Collection
    {
        $tickets = $paste->records
            ->filter(fn (Ticket $ticket) => (int) $ticket->service_order_id !== $order->id)
            ->values();

        if ($tickets->isEmpty()) {
            throw new Refusal($paste->these('staat', 'staan') . ' al op werkbon ' . $order->number . '.');
        }

        $tickets->each(function (Ticket $ticket) use ($order) {
            $customer_id = TicketKind::customerId($ticket);

            if ($customer_id !== null && $customer_id !== (int) $order->customer_id) {
                throw new Refusal('"' . TicketKind::title($ticket) . '" hoort bij een andere klant dan werkbon '
                    . $order->number . '.');
            }
        });

        return $tickets;
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return Collection<int, Ticket>
     */
    private function moving(Collection $tickets): Collection
    {
        return $tickets->filter(fn (Ticket $ticket) => $ticket->service_order_id !== null)->values();
    }
}
