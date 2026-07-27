<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Carries names rather than ids because the log quotes the previous customer and
 * that customer may later be deleted. The log has to survive that.
 */
class ServiceOrderCustomerChanged extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?int $previous_customer_id,
        public ?string $previous_customer_name,
        public ?string $new_customer_name,
        public ?string $previous_contract_title = null,
        public ?string $reason = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.customer_changed';
    }

    public static function label(): string
    {
        return 'Werkbon klant gewijzigd';
    }

    public function serviceOrder(): ServiceOrder
    {
        return $this->service_order;
    }

    public static function coveredFields(): array
    {
        return ['customer_id'];
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        $suffix = $this->reason ? ' (' . $this->reason . ')' : '';

        return "Klant gewijzigd van '" . $this->previous_customer_name
            . "' naar '" . $this->new_customer_name . "'" . $suffix;
    }
}
