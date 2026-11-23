<?php

namespace App\Actions\Pricing;

use App\Domain\Pricing\MovedContracts;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use Illuminate\Support\Collection;

/**
 * Moves contracts on an older version of a formula to its newest one, each
 * repriced and with a line on its own timeline. Asked for all of them, it leaves
 * the ended ones alone: they keep the price they ended with.
 */
class UpgradeContractsToLatestVersionAction
{
    public function __construct(private readonly LinkContractPriceFormulaAction $link) {}

    /**
     * The newest version is read from the database, not from a loaded relation:
     * right after a save that made it, a loaded one is the version before.
     *
     * @param  array<int, int>|null  $contract_ids  null for every running contract on an older version
     */
    public function execute(PriceFormula $formula, ?array $contract_ids = null): MovedContracts
    {
        $latest = $formula->latestVersion()->first();

        if ($latest === null) {
            return MovedContracts::none();
        }

        $contracts = $formula->contractsBehind()
            ->when(
                $contract_ids !== null,
                fn ($query) => $query->whereKey($contract_ids),
                fn ($query) => $query->notEnded(),
            )
            ->with('priceFormulaVersion.formula')
            ->get();

        $before = self::periodicTotal($contracts);
        $this->link->executeMany($contracts, $latest);

        return new MovedContracts($contracts->count(), $before, self::periodicTotal($contracts), $latest);
    }

    /** @param Collection<int, MaintenanceContract> $contracts */
    public static function periodicTotal(Collection $contracts): float
    {
        return round($contracts->sum(fn (MaintenanceContract $contract) => (float) $contract->getRawOriginal('price')), 2);
    }
}
