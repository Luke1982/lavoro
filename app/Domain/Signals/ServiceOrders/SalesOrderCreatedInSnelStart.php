<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Carries the external order reference itself rather than a finished sentence, so
 * a werkbon can be traced back to its SnelStart order without parsing Dutch.
 */
class SalesOrderCreatedInSnelStart extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?string $external_order_id,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.snelstart_order_created';
    }

    public static function label(): string
    {
        return 'Verkooporder aangemaakt in SnelStart';
    }

    public static function coveredFields(): array
    {
        return ['sent_to_administration'];
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function activityDescription(): ?string
    {
        return 'Werkbon naar administratie verzonden (SnelStart verkooporder ID: '
            . ($this->external_order_id ?? 'onbekend') . ').';
    }

    public function activityMetadata(): ?array
    {
        return ['snelstart_order_id' => $this->external_order_id];
    }

    public function changes(): array
    {
        return [[
            'field' => 'sent_to_administration',
            'label' => 'Verzonden naar administratie',
            'old_value' => '0',
            'new_value' => '1',
            'old_label' => 'Nee',
            'new_label' => 'Ja',
        ]];
    }
}
