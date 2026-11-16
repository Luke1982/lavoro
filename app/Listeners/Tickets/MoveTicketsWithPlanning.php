<?php

namespace App\Listeners\Tickets;

use App\Domain\Planning\Clock;
use App\Domain\Signals\ServiceOrders\ServiceOrderScheduled;
use App\Domain\Signals\ServiceOrders\ServiceOrderUnscheduled;
use App\Domain\Signals\ServiceOrders\TicketAttachedToOrder;
use App\Domain\Signals\ServiceOrders\TicketDetachedFromOrder;
use App\Enums\TicketStatusses;
use App\Models\Ticket;

/**
 * A storing is in behandeling while the werkbon it is on has an appointment that
 * goes ahead, and open again once it has none.
 *
 * Only the statuses that rule is about move. Going in, a storing that is open or
 * waiting on the customer: a visit is planned, so someone is on it. Going out,
 * only one in behandeling: a storing somebody closed, or put on waiting while it
 * was planned, was put there by a person who knew more than this rule does.
 */
class MoveTicketsWithPlanning
{
    public function handleScheduled(ServiceOrderScheduled $signal): void
    {
        if (!$signal->appointment->isCancelled()) {
            $this->putInProgress(
                $signal->service_order->tickets()->get(),
                'ingepland op ' . Clock::toLocal($signal->appointment->start)->format('d-m-Y H:i')
                    . ' via werkbon ' . $signal->service_order->number,
            );
        }
    }

    public function handleUnscheduled(ServiceOrderUnscheduled $signal): void
    {
        if (!$signal->service_order->hasAppointment()) {
            $this->reopen(
                $signal->service_order->tickets()->get(),
                'geen afspraak meer op werkbon ' . $signal->service_order->number,
            );
        }
    }

    public function handleAttached(TicketAttachedToOrder $signal): void
    {
        if ($signal->service_order->hasAppointment()) {
            $this->putInProgress(
                [$signal->ticket],
                'gekoppeld aan ingeplande werkbon ' . $signal->service_order->number,
            );
        }
    }

    public function handleDetached(TicketDetachedFromOrder $signal): void
    {
        if ($signal->service_order->hasAppointment()) {
            $this->reopen(
                [$signal->ticket],
                'losgekoppeld van ingeplande werkbon ' . $signal->service_order->number,
            );
        }
    }

    /** @param  iterable<Ticket>  $tickets */
    private function putInProgress(iterable $tickets, string $reason): void
    {
        $this->move(
            $tickets,
            [TicketStatusses::open, TicketStatusses::wacht_op_klant],
            TicketStatusses::in_behandeling,
            $reason,
        );
    }

    /** @param  iterable<Ticket>  $tickets */
    private function reopen(iterable $tickets, string $reason): void
    {
        $this->move($tickets, [TicketStatusses::in_behandeling], TicketStatusses::open, $reason);
    }

    /**
     * @param  iterable<Ticket>  $tickets
     * @param  array<int, TicketStatusses>  $from
     */
    private function move(iterable $tickets, array $from, TicketStatusses $to, string $reason): void
    {
        foreach ($tickets as $ticket) {
            if (in_array(TicketStatusses::tryFrom($ticket->status), $from, true)) {
                $ticket->moveToStatus($to, $reason);
            }
        }
    }
}
