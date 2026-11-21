<?php

namespace App\Domain\Signals\Contracts;

use App\Domain\Contracts\ContractLabels;
use App\Domain\Signals\BaseSignal;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use Illuminate\Database\Eloquent\Model;

/**
 * Machines left a contract because they went to another customer, one entry for
 * all of them. Told on the contract and on each machine, where it says under the
 * contract's name that the machine left it by being transferred.
 */
class ContractAssetDetachedByTransfer extends BaseSignal
{
    use NamesTheContract;

    /**
     * @param  array<int, Asset>  $assets
     */
    public function __construct(
        public MaintenanceContract $contract,
        public array $assets,
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
        if ($this->assets === []) {
            return null;
        }

        return (count($this->assets) === 1 ? 'Machine' : 'Machines')
            . ' losgekoppeld van contract door overdracht naar andere klant: '
            . implode(', ', array_map(fn (Asset $asset) => ContractLabels::asset($asset), $this->assets));
    }

    public function activityContext(): array
    {
        return array_values($this->assets);
    }

    public function activityMetadata(): ?array
    {
        return $this->withContractLabel([
            'asset_ids' => array_map(fn (Asset $asset) => $asset->getKey(), $this->assets),
        ]);
    }
}
