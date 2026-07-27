<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractAutoGenerationEnabled extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
        public string $frequency_label,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.autogeneration_enabled';
    }

    public static function label(): string
    {
        return 'Automatisch genereren ingeschakeld';
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public static function coveredFields(): array
    {
        return ['auto_generate'];
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        return 'Automatisch genereren van werkbonnen ingeschakeld (frequentie: ' . $this->frequency_label . ')';
    }

    public function changes(): array
    {
        return [[
            'field' => 'auto_generate',
            'label' => 'Automatisch genereren',
            'old_value' => '0',
            'new_value' => '1',
            'old_label' => 'Nee',
            'new_label' => 'Ja',
        ]];
    }
}
