<?php

namespace App\Http\Controllers;

use App\Actions\Pricing\LinkContractPriceFormulaAction;
use App\Http\Requests\MaintenanceContractPriceFormulaUpdateRequest;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use App\Models\PriceFormulaVersion;
use App\Support\Money;

class MaintenanceContractPriceFormulaController extends Controller
{
    /** Whoever changed the formula of a contract hears what the contract costs now. */
    public function update(
        MaintenanceContractPriceFormulaUpdateRequest $request,
        MaintenanceContract $maintenancecontract
    ) {
        $validated = $request->validated();
        $version = $maintenancecontract->priceFormulaVersion;

        if (array_key_exists('price_formula_id', $validated)) {
            $formula_id = $request->integer('price_formula_id') ?: null;
            $version = $this->chosenVersion($maintenancecontract, $formula_id, $request->boolean('to_latest'));
        }

        app(LinkContractPriceFormulaAction::class)->execute(
            $maintenancecontract,
            $version,
            array_key_exists('inputs', $validated) ? ($validated['inputs'] ?? []) : null,
        );

        if ($version === null) {
            return redirect()->back()
                ->with('success', 'Prijsformule losgekoppeld. De prijs blijft zoals hij was; voortaan vul je hem zelf in.');
        }

        $maintenancecontract->refresh();
        $message = 'De prijs is nu ' . Money::euros($maintenancecontract->price) . ' per periode';

        if ($maintenancecontract->one_time_price !== null) {
            $message .= ', plus ' . Money::euros($maintenancecontract->one_time_price) . ' eenmalig';
        }

        return redirect()->back()->with('success', $message . '.');
    }

    /**
     * Another formula puts the contract on its newest version; the same one again
     * only moves it on when asked to, and nothing takes the formula off.
     */
    private function chosenVersion(
        MaintenanceContract $contract,
        ?int $formula_id,
        bool $to_latest
    ): ?PriceFormulaVersion {
        $formula = $formula_id === null ? null : PriceFormula::with('latestVersion')->find($formula_id);
        $current = $contract->priceFormulaVersion;

        if ($formula === null) {
            return null;
        }

        return $formula->id !== $current?->price_formula_id || $to_latest ? $formula->latestVersion : $current;
    }
}
