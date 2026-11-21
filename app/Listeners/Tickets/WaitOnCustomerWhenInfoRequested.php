<?php

namespace App\Listeners\Tickets;

use App\Domain\Signals\Tickets\CustomerInfoRequested;
use App\Enums\TicketStatusses;

/**
 * A storing whose customer was asked for more information waits on that
 * customer. The request is only announced once its mail has gone out, so a
 * storing never waits on a question nobody received.
 */
class WaitOnCustomerWhenInfoRequested
{
    public function handle(CustomerInfoRequested $signal): void
    {
        $signal->ticket->moveToStatus(TicketStatusses::wacht_op_klant, 'informatie opgevraagd bij de klant');
    }
}
