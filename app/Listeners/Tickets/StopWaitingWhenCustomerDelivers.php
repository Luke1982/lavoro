<?php

namespace App\Listeners\Tickets;

use App\Domain\Signals\Tickets\CustomerInfoUploaded;
use App\Enums\TicketStatusses;

/**
 * Nobody waits on the customer any more once they delivered, so neither does the
 * storing.
 *
 * Only from that one status. A colleague who put the storing somewhere else in
 * the meantime knows more than an incoming file does, and that file should not
 * undo their decision. In behandeling is where a storing stands while it is being
 * worked on.
 */
class StopWaitingWhenCustomerDelivers
{
    public function handle(CustomerInfoUploaded $signal): void
    {
        if ($signal->ticket->status !== TicketStatusses::wacht_op_klant->value) {
            return;
        }

        $signal->ticket->moveToStatus(TicketStatusses::in_behandeling, 'klant leverde informatie aan');
    }
}
