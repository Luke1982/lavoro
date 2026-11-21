<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

class TicketAttachedToOrder extends BaseSignal
{
    use NamesTheTicket;

    public function __construct(
        public ServiceOrder $service_order,
        public Ticket $ticket,
        public ?Asset $asset,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.ticket_attached';
    }

    public static function label(): string
    {
        return 'Storing gekoppeld aan werkbon';
    }

    public function activityCategory(): string
    {
        return 'ticket';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        return 'Storing gekoppeld: ' . $this->ticketLabel();
    }
}
