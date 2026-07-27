<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractAssetFrequencyChanged extends BaseSignal
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
        return 'contract.asset_frequency_changed';
    }

    public static function label(): string
    {
        return 'Machinefrequentie gewijzigd';
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
        return 'Machinefrequentie bijgewerkt: ' . $this->asset_label
            . ' naar ' . ($this->frequency_label ?? 'contractfrequentie');
    }
}
