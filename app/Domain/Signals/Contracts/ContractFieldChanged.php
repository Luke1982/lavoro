<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractFieldChanged extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
        public string $field,
        public string $field_label,
        public ?string $previous_display,
        public ?string $new_display,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.field_changed';
    }

    public static function label(): string
    {
        return 'Contractveld gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    /**
     * This signal reports whichever contract field changed, including the money
     * ones, so it asks the contract itself whether that field is gated.
     */
    public function requiredPermission(): ?string
    {
        return $this->contract->permissionForField($this->field);
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        return sprintf("%s gewijzigd van '%s' naar '%s'", $this->field_label, $this->previous_display ?? '-', $this->new_display ?? '-');
    }

    public function changes(): array
    {
        return [[
            'field' => $this->field,
            'label' => $this->field_label,
            'old_value' => $this->previous_display,
            'new_value' => $this->new_display,
            'old_label' => $this->previous_display,
            'new_label' => $this->new_display,
        ]];
    }
}
