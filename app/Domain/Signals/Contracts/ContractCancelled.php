<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Planning\Clock;
use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractCancelled extends BaseSignal
{
    use ReachesTheContractsCustomer;

    public function __construct(
        public MaintenanceContract $contract,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.cancelled';
    }

    public static function label(): string
    {
        return 'Contract geannuleerd';
    }

    public static function coveredFields(): array
    {
        return ['cancelled_at'];
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        return 'Contract geannuleerd';
    }

    public function activityMetadata(): ?array
    {
        return $this->withContractLabel();
    }

    public function changes(): array
    {
        $cancelled_at = $this->contract->cancelled_at;

        return [[
            'field' => 'cancelled_at',
            'label' => 'Geannuleerd op',
            'old_value' => null,
            'new_value' => $cancelled_at?->format('Y-m-d H:i:s'),
            'old_label' => null,
            'new_label' => $cancelled_at ? Clock::toLocal($cancelled_at)->format('d-m-Y') : null,
        ]];
    }
}
