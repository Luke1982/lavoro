<?php

namespace App\Actions\Pricing;

use App\Actions\Contracts\RecalculateContractPriceAction;
use App\Domain\Pricing\ContractPricing;
use App\Domain\Signals\Contracts\ContractPriceFormulaChanged;
use App\Domain\Signals\Signals;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use App\Models\PriceFormulaVersion;
use Illuminate\Support\Facades\DB;

/**
 * Puts a contract on a version of a price formula, moves it to a newer one,
 * changes what is filled in for the formula's inputs, or takes the formula off
 * again. Whatever changed goes on the timeline and the price follows at once.
 *
 * Inputs are kept by block, so a value filled in survives a newer version as long
 * as that block is still in it.
 */
class LinkContractPriceFormulaAction
{
    public function __construct(private readonly RecalculateContractPriceAction $recalculate) {}

    /** @param array<string, mixed>|null $inputs null keeps what is filled in now */
    public function execute(MaintenanceContract $contract, ?PriceFormulaVersion $version, ?array $inputs = null): void
    {
        DB::transaction(function () use ($contract, $version, $inputs) {
            if ($version !== null) {
                PriceFormula::whereKey($version->price_formula_id)->lockForUpdate()->first();
                $version->refresh();
            }

            $previous_version = $contract->priceFormulaVersion;
            $previous_inputs = $contract->price_formula_inputs ?? [];
            $previous_one_time = $contract->one_time_price;
            $wanted = $inputs ?? $previous_inputs;

            $fields = $version ? ContractPricing::inputsOf($version->graph) : [];
            $kept = [];
            foreach ($fields as $field) {
                if (isset($wanted[$field['id']]) && is_numeric($wanted[$field['id']])) {
                    $kept[$field['id']] = (float) $wanted[$field['id']];
                }
            }

            $contract->forceFill([
                'price_formula_version_id' => $version?->id,
                'price_formula_inputs' => $kept === [] ? null : $kept,
                ...($version === null ? ['one_time_price' => null] : []),
            ])->save();

            $input_changes = [];
            foreach ($fields as $field) {
                $old = $previous_inputs[$field['id']] ?? null;
                $new = $kept[$field['id']] ?? null;
                if ($previous_version?->id === $version?->id && (string) $old !== (string) $new) {
                    $input_changes[] = [
                        'name' => $field['name'],
                        'old' => $old,
                        'new' => $new,
                        'default' => $field['default'],
                    ];
                }
            }

            if ($previous_version?->id !== $version?->id || $input_changes !== []) {
                Signals::dispatch(new ContractPriceFormulaChanged(
                    $contract,
                    $previous_version?->displayName(),
                    $version?->displayName(),
                    $input_changes,
                    $version === null && $previous_one_time !== null ? (float) $previous_one_time : null,
                ));
            }

            $contract->setRelation('priceFormulaVersion', $version);
            $this->recalculate->execute($contract, even_if_ended: true);
        });
    }
}
