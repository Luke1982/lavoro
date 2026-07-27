<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractAutoGenerationDisabled extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.autogeneration_disabled';
    }

    public static function label(): string
    {
        return 'Automatisch genereren uitgeschakeld';
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
        return 'Automatisch genereren van werkbonnen uitgeschakeld';
    }

    public function changes(): array
    {
        return [[
            'field' => 'auto_generate',
            'label' => 'Automatisch genereren',
            'old_value' => '1',
            'new_value' => '0',
            'old_label' => 'Ja',
            'new_label' => 'Nee',
        ]];
    }
}
