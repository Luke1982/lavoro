<?php

namespace App\Actions\Contracts;

use App\Domain\Contracts\ContractLabels;
use App\Domain\Signals\Contracts\ContractAutoGenerationEnabled;
use App\Domain\Signals\Contracts\ContractCreated;
use App\Domain\Signals\Signals;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use Illuminate\Support\Facades\DB;

/**
 * Draws up a maintenance contract, with the machines it covers if they are known.
 *
 * The screen creates the contract first and attaches machines one at a time
 * afterwards; a contract read off a signed quote arrives with its machines
 * already named. Both run through here, so either way the timeline opens with
 * "contract aangemaakt" followed by one line per machine.
 *
 * The attributes are whatever MaintenanceContractStoreRequest's rules let
 * through: that request defines the shape, for the form and the assistant alike.
 */
class CreateMaintenanceContractAction
{
    public function __construct(private readonly AttachContractAssetAction $attach) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  iterable<int, Asset>  $assets  Attached following the contract's own frequency.
     */
    public function execute(array $attributes, iterable $assets = []): MaintenanceContract
    {
        return DB::transaction(function () use ($attributes, $assets) {
            $contract = MaintenanceContract::create($attributes);
            Signals::dispatch(new ContractCreated($contract));

            /**
             * A contract can start out generating its own work orders, straight from a
             * template. Reported here as well as on the toggle, so the timeline never
             * has work orders appearing out of nowhere.
             */
            if ($contract->auto_generate) {
                Signals::dispatch(new ContractAutoGenerationEnabled(
                    $contract,
                    ContractLabels::autoGeneration($contract)
                ));
            }

            foreach ($assets as $asset) {
                $this->attach->execute($contract, $asset);
            }

            return $contract;
        });
    }
}
