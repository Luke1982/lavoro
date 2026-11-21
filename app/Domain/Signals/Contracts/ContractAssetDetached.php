<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Contracts\ContractLabels;
use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

/**
 * A machine was taken off a contract. Told on the contract and on the machine,
 * not on the customer: which machines a contract covers is the contract's
 * business. Also when the contract itself is deleted: then nothing is left to
 * reprice, and the machine still hears that it is no longer on that contract.
 */
class ContractAssetDetached extends BaseSignal
{
    use NamesTheContract;

    public function __construct(
        public MaintenanceContract $contract,
        public Asset $asset,
        public bool $contract_deleted = false,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.asset_detached';
    }

    public static function label(): string
    {
        return 'Machine losgekoppeld van contract';
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        return 'Machine losgekoppeld: ' . ContractLabels::asset($this->asset)
            . ($this->contract_deleted ? ' door verwijdering van het contract' : '');
    }

    /** On the machine's own timeline, under the contract's name. */
    public function activityContext(): array
    {
        return [$this->asset];
    }

    public function activityMetadata(): ?array
    {
        return $this->withContractLabel();
    }
}
