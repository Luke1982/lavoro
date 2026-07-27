<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractCreated extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.created';
    }

    public static function label(): string
    {
        return 'Contract aangemaakt';
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function activityDescription(): ?string
    {
        return 'Contract aangemaakt';
    }
}
