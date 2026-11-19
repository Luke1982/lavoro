<?php

namespace App\Actions\Pricing;

use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use Illuminate\Support\Facades\DB;

/**
 * Moves contracts on an older version of a formula to its newest one, each
 * repriced and with a line on its own timeline. Asked for all of them, it leaves
 * the ended ones alone: they keep the price they ended with.
 */
class UpgradeContractsToLatestVersionAction
{
    public function __construct(private readonly LinkContractPriceFormulaAction $link) {}

    /** @param array<int, int>|null $contract_ids null for every contract on an older version */
    public function execute(PriceFormula $formula, ?array $contract_ids = null): int
    {
        $latest = $formula->latestVersion;

        if ($latest === null) {
            return 0;
        }

        $contracts = MaintenanceContract::query()
            ->whereIn('price_formula_version_id', $formula->versions()->where('id', '!=', $latest->id)->select('id'))
            ->when(
                $contract_ids !== null,
                fn ($query) => $query->whereKey($contract_ids),
                fn ($query) => $query->notEnded(),
            )
            ->with('priceFormulaVersion.formula')
            ->get();

        DB::transaction(function () use ($contracts, $latest) {
            foreach ($contracts as $contract) {
                $this->link->execute($contract, $latest);
            }
        });

        return $contracts->count();
    }
}
