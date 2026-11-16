<?php

namespace App\Actions\Contracts;

use App\Domain\Contracts\ContractLabels;
use App\Domain\Signals\Contracts\ContractAssetDetached;
use App\Domain\Signals\Signals;
use App\Models\Asset;
use App\Models\MaintenanceContract;

/**
 * Takes a machine off a maintenance contract: from the contract screen, and when
 * a paste off the bakje is undone. Both leave the same line on the timeline.
 */
class DetachContractAssetAction
{
    public function execute(MaintenanceContract $contract, int $assetable_id): void
    {
        $pivot_query = $contract->assets()->newPivotQuery()->where('assetables.id', $assetable_id);
        $record = $pivot_query->first();
        $asset = $record ? Asset::find($record->asset_id) : null;

        $pivot_query->delete();

        Signals::dispatch(new ContractAssetDetached(
            $contract,
            $asset ? ContractLabels::asset($asset) : null,
        ));
    }
}
