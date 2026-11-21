<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

class TicketDetachedFromOrder extends BaseSignal
{
    use NamesTheTicket;

    public function __construct(
        public ServiceOrder $service_order,
        public Ticket $ticket,
        public ?Asset $asset,
        public bool $order_deleted = false,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.ticket_detached';
    }

    public static function label(): string
    {
        return 'Storing losgekoppeld van werkbon';
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
        if ($this->order_deleted) {
            return 'Storing losgekoppeld omdat werkbon ' . $this->service_order->number . ' is verwijderd: '
                . $this->ticketLabel();
        }

        return 'Storing losgekoppeld: ' . $this->ticketLabel();
    }

    /**
     * A werkbon being deleted takes its own timeline with it, so the storing it
     * leaves behind is the only place that can still tell the story.
     */
    public function activityContext(): array
    {
        return $this->order_deleted ? [$this->ticket] : [];
    }
}
