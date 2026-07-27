<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractCustomerChanged extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
        public ?string $previous_customer_name,
        public ?string $new_customer_name,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.customer_changed';
    }

    public static function label(): string
    {
        return 'Contract klant gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    public static function coveredFields(): array
    {
        return ['customer_id'];
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        return sprintf("Klant gewijzigd van '%s' naar '%s'", $this->previous_customer_name, $this->new_customer_name);
    }

    public function changes(): array
    {
        return [[
            'field' => 'customer_id',
            'label' => 'Klant',
            'old_value' => $this->previous_customer_name,
            'new_value' => $this->new_customer_name,
            'old_label' => $this->previous_customer_name,
            'new_label' => $this->new_customer_name,
        ]];
    }
}
