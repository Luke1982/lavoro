<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

class TicketDetachedFromOrder extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public Ticket $ticket,
        public ?Asset $asset,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.ticket_detached';
    }

    public static function label(): string
    {
        return 'Ticket losgekoppeld van werkbon';
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
        return sprintf(
            'Ticket losgekoppeld: %s (%s %s %s, serienummer %s)',
            $this->ticket->subject ?? ('Ticket #' . $this->ticket->id),
            $this->asset?->product?->productType?->name ?? 'Type',
            $this->asset?->product?->brand?->name ?? 'Merk',
            $this->asset?->product?->model ?? '',
            $this->asset?->serial_number ?? '-'
        );
    }
}
