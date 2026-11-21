<?php

namespace App\Actions\Contracts;

use App\Actions\Pricing\LinkContractPriceFormulaAction;
use App\Domain\Contracts\ContractLabels;
use App\Domain\Signals\Contracts\ContractAutoGenerationEnabled;
use App\Domain\Signals\Contracts\ContractCreated;
use App\Domain\Signals\Signals;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use App\Models\MaintenanceContractTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Draws up a maintenance contract, with the machines it covers if they are known.
 *
 * The screen creates the contract first and attaches machines one at a time
 * afterwards; a contract read off a signed quote arrives with its machines
 * already named. Both run through here, so either way the timeline opens with
 * the contract's own "Aangemaakt" followed by one line per machine.
 *
 * The attributes are whatever MaintenanceContractStoreRequest's rules let
 * through: that request defines the shape, for the form and the assistant alike.
 *
 * A template with a price formula puts the contract on that formula's newest
 * version once its machines are on, so the price is worked out once, over all
 * of them, rather than again for every machine attached.
 */
class CreateMaintenanceContractAction
{
    public function __construct(
        private readonly AttachContractAssetAction $attach,
        private readonly LinkContractPriceFormulaAction $link_formula,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  iterable<int, Asset>  $assets  Attached following the contract's own frequency.
     */
    public function execute(array $attributes, iterable $assets = []): MaintenanceContract
    {
        return DB::transaction(function () use ($attributes, $assets) {
            $template = isset($attributes['maintenance_contract_template_id'])
                ? MaintenanceContractTemplate::with('priceFormula.latestVersion')
                    ->find($attributes['maintenance_contract_template_id'])
                : null;
            unset($attributes['maintenance_contract_template_id']);

            $version = $template?->priceFormula?->latestVersion;

            if ($version !== null) {
                $attributes['price'] = 0;
            }

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

            if ($version !== null) {
                $this->link_formula->execute($contract, $version);
            }

            return $contract;
        });
    }
}
