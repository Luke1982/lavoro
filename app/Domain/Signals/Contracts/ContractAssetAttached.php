<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractAssetAttached extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
        public string $asset_label,
        public ?string $frequency_label,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.asset_attached';
    }

    public static function label(): string
    {
        return 'Machine gekoppeld aan contract';
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
        return 'Machine gekoppeld: ' . $this->asset_label
            . ($this->frequency_label ? ' (frequentie: ' . $this->frequency_label . ')' : '');
    }
}
