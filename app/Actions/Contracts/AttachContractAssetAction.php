<?php

namespace App\Actions\Contracts;

use App\Domain\Contracts\ContractLabels;
use App\Domain\Signals\Contracts\ContractAssetAttached;
use App\Domain\Signals\Signals;
use App\Models\Asset;
use App\Models\MaintenanceContract;

/**
 * Puts a machine on a maintenance contract.
 *
 * The pivot row is what the work order generator reads, and the signal is what
 * the contract's timeline shows. A machine attached without the second is one
 * that turns up on generated work orders with nobody able to say since when.
 */
class AttachContractAssetAction
{
    /**
     * @param  string|null  $frequency  Only on a contract with a frequency per machine; null follows the contract.
     */
    public function execute(
        MaintenanceContract $contract,
        Asset $asset,
        ?string $frequency = null,
        ?int $frequency_days = null,
    ): void {
        $contract->assets()->attach($asset->id, [
            'frequency' => $frequency,
            'frequency_days' => $frequency_days,
        ]);

        Signals::dispatch(new ContractAssetAttached(
            $contract,
            ContractLabels::asset($asset),
            $frequency ? ContractLabels::frequency($frequency, $frequency_days) : null,
        ));
    }
}
