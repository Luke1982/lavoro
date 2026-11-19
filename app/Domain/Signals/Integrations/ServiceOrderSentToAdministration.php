<?php

namespace App\Domain\Signals\Integrations;

use App\Domain\Integrations\DocumentForm;
use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\ServiceOrders\ServiceOrderSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * A werkbon went to a linked system and became a sales order or an invoice
 * there. Carries the id and number it got there rather than only a sentence,
 * so a werkbon can be traced to its counterpart without parsing Dutch.
 *
 * One per system. Whether the werkbon as a whole now counts as sent is its own
 * change, recorded when every system has it: with two systems the first can
 * succeed and the second refuse.
 *
 * Replaces serviceorder.snelstart_order_created, which still stands in the
 * trail of werkbonnen sent before every system went through one door.
 */
class ServiceOrderSentToAdministration extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public string $connector,
        public string $system,
        public DocumentForm $form,
        public string $external_id,
        public ?string $number = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'integration.serviceorder_sent';
    }

    public static function label(): string
    {
        return 'Werkbon naar de administratie verzonden';
    }

    public function serviceOrder(): ServiceOrder
    {
        return $this->service_order;
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->service_order->customer]));
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function activityDescription(): ?string
    {
        return 'Werkbon naar ' . $this->system . ' verzonden als ' . mb_strtolower($this->form->label())
            . ' (' . ($this->number ? 'nummer ' . $this->number : 'ID ' . $this->external_id) . ').';
    }

    public function activityMetadata(): ?array
    {
        return [
            'connector' => $this->connector,
            'form' => $this->form->value,
            'external_id' => $this->external_id,
            'number' => $this->number,
        ];
    }
}
