<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractCancelled extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.cancelled';
    }

    public static function label(): string
    {
        return 'Contract geannuleerd';
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
        return 'Contract geannuleerd';
    }
}
