<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class ContractServiceOrderGenerated extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
        public ServiceOrder $service_order,
        public string $verb,
        public array $asset_labels,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.serviceorder_generated';
    }

    public static function label(): string
    {
        return 'Werkbon gegenereerd uit contract';
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        return sprintf(
            'Werkbon #%d %s voor %s',
            $this->service_order->id,
            $this->verb,
            implode(', ', $this->asset_labels)
        );
    }
}
