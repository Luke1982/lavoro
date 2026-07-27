<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractAssetDetached extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
        public ?string $asset_label,
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
        return 'Machine losgekoppeld' . ($this->asset_label ? ': ' . $this->asset_label : '');
    }
}
