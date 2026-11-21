<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Planning\Clock;
use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A cancelled contract runs again. Carries the moment it was cancelled, which the
 * contract no longer holds once it has been reactivated.
 */
class ContractReactivated extends BaseSignal
{
    use ReachesTheContractsCustomer;

    public function __construct(
        public MaintenanceContract $contract,
        public DateTimeInterface $was_cancelled_at,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.reactivated';
    }

    public static function label(): string
    {
        return 'Contract heractiveerd';
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
        return 'Contract heractiveerd';
    }

    public function activityMetadata(): ?array
    {
        return $this->withContractLabel();
    }

    public function changes(): array
    {
        return [[
            'field' => 'cancelled_at',
            'label' => 'Geannuleerd op',
            'old_value' => $this->was_cancelled_at->format('Y-m-d H:i:s'),
            'new_value' => null,
            'old_label' => Clock::toLocal($this->was_cancelled_at)->format('d-m-Y'),
            'new_label' => null,
        ]];
    }
}
