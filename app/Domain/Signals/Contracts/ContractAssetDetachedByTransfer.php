<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Signals\BaseSignal;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

class ContractAssetDetachedByTransfer extends BaseSignal
{
    public function __construct(
        public MaintenanceContract $contract,
        public array $asset_labels,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'contract.asset_detached_by_transfer';
    }

    public static function label(): string
    {
        return 'Machine losgekoppeld door overdracht';
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
        return 'Machine losgekoppeld van contract door overdracht naar andere klant: '
            . implode(', ', $this->asset_labels);
    }
}
