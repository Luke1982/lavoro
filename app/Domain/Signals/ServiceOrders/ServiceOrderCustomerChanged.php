<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\Customer;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Carries names rather than ids because the log quotes the previous customer and
 * that customer may later be deleted. The log has to survive that.
 *
 * Told on both customers' timelines: the one the werkbon left and the one it went
 * to are both places someone looks for it.
 */
class ServiceOrderCustomerChanged extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?int $previous_customer_id,
        public ?string $previous_customer_name,
        public ?string $new_customer_name,
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

    public function activityCategory(): string
    {
        return 'updated';
    }

    /**
     * Looked up by id rather than through the relation: the caller may have loaded
     * the customer before moving the werkbon, and that relation still names the old one.
     */
    public function activityContext(): array
    {
        $ids = array_values(array_filter([$this->previous_customer_id, $this->service_order->customer_id]));

        return $ids === [] ? [] : Customer::query()->whereKey($ids)->get()->all();
    }

    public function changes(): array
    {
        $new_customer_id = $this->service_order->customer_id;

        return [[
            'field' => 'customer_id',
            'label' => 'Klant',
            'old_value' => $this->previous_customer_id === null ? null : (string) $this->previous_customer_id,
            'new_value' => $new_customer_id === null ? null : (string) $new_customer_id,
            'old_label' => $this->previous_customer_name,
            'new_label' => $this->new_customer_name,
        ]];
    }
}
