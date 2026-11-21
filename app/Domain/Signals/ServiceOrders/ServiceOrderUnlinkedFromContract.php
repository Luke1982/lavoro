<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * A werkbon stopped being a contract's work. Carries the contract's title as it
 * was, so the entry still reads right after the contract is renamed or deleted.
 * Told on the contract and on its customer too: that is where someone looks for
 * the werkbon that used to be there. Not when the contract itself is deleted:
 * then the contract's own "Verwijderd" is the news at the customer, and one line
 * per werkbon there would only repeat it.
 */
class ServiceOrderUnlinkedFromContract extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?MaintenanceContract $contract,
        public ?string $contract_title = null,
        public ?string $reason = null,
        public bool $contract_deleted = false,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.unlinked_from_contract';
    }

    public static function label(): string
    {
        return 'Werkbon losgekoppeld van contract';
    }

    public function serviceOrder(): ServiceOrder
    {
        return $this->service_order;
    }

    public static function coveredFields(): array
    {
        return ['maintenance_contract_id'];
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        $suffix = $this->reason ? ' ' . $this->reason : '';

        return 'Losgekoppeld van contract ' . ($this->contract_title ?? 'onbekend') . $suffix;
    }

    public function activityContext(): array
    {
        if ($this->contract_deleted) {
            return [];
        }

        return array_values(array_filter([$this->contract, $this->contract?->customer]));
    }

    /** The werkbon by number, so the contract's timeline can link the werkbon that left it. */
    public function activityMetadata(): ?array
    {
        return [
            'service_order_id' => $this->service_order->getKey(),
            'service_order_number' => $this->service_order->number,
        ];
    }

    public function changes(): array
    {
        return [[
            'field' => 'maintenance_contract_id',
            'label' => 'Contract',
            'old_value' => $this->contract?->getKey() === null ? null : (string) $this->contract->getKey(),
            'new_value' => null,
            'old_label' => $this->contract_title,
            'new_label' => null,
        ]];
    }
}
