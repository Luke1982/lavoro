<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractFrequencyModeChanged extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
        public bool $per_asset,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.frequency_mode_changed';
    }

    public static function label(): string
    {
        return 'Frequentiebeheer gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public static function coveredFields(): array
    {
        return ['manage_frequency_per_asset'];
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        return 'Frequentiebeheer gewijzigd naar ' . ($this->per_asset ? 'per machine' : 'contractbreed');
    }

    public function changes(): array
    {
        return [[
            'field' => 'manage_frequency_per_asset',
            'label' => 'Frequentie per machine',
            'old_value' => $this->per_asset ? '0' : '1',
            'new_value' => $this->per_asset ? '1' : '0',
            'old_label' => $this->per_asset ? 'Nee' : 'Ja',
            'new_label' => $this->per_asset ? 'Ja' : 'Nee',
        ]];
    }
}
