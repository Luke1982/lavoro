<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * An external invoice number was put on a werkbon for the first time. What that
 * means for the werkbon's stage is a subscriber's decision, not the caller's.
 */
class ServiceOrderInvoiceRecorded extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public string $invoice_number,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.invoice_recorded';
    }

    public static function label(): string
    {
        return 'Werkbon extern factuurnummer vastgelegd';
    }

    public function serviceOrder(): ServiceOrder
    {
        return $this->service_order;
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        return 'Extern factuurnummer vastgelegd: ' . $this->invoice_number;
    }

    public function activityCategory(): string
    {
        return 'status';
    }
}
