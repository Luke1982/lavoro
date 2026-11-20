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
    /**
     * Each contract raises a few signals: the formula it moved to, the history
     * of the record, and the new price. A hundred stay well under what one unit
     * of work may raise.
     */
    private const CONTRACTS_PER_SIGNAL_UNIT = 100;

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
            $same_version = $previous_version?->id === $version?->id;
            $fields = $version ? ContractPricing::inputsOf($version->graph) : [];

            $kept = [];
            $input_changes = [];
            foreach ($fields as $field) {
                if (isset($wanted[$field['id']]) && is_numeric($wanted[$field['id']])) {
                    $kept[$field['id']] = (float) $wanted[$field['id']];
                }

                $old = $previous_inputs[$field['id']] ?? null;
                $new = $kept[$field['id']] ?? null;
                if ($same_version && (string) $old !== (string) $new) {
                    $input_changes[] = [
                        'name' => $field['name'],
                        'old' => $old,
                        'new' => $new,
                        'default' => $field['default'],
                    ];
                }
            }

            $contract->forceFill([
                'price_formula_version_id' => $version?->id,
                'price_formula_inputs' => $kept === [] ? null : $kept,
                ...($version === null ? ['one_time_price' => null] : []),
            ])->save();

            if (!$same_version || $input_changes !== []) {
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

    /**
     * Many contracts onto one version, all of them or none. One request may
     * raise only so many signals, against a cascade running away, and moving a
     * few hundred contracts would reach that ceiling and leave the rest without
     * a line on their timeline. This work is bounded by the contracts given, so
     * every hundred of them start a fresh count; never inside a signal's own
     * chain, where starting over would break the loop detection.
     *
     * @param  iterable<int, MaintenanceContract>  $contracts
     */
    public function executeMany(iterable $contracts, PriceFormulaVersion $version): void
    {
        $signals = app(Signals::class);

        DB::transaction(function () use ($contracts, $version, $signals) {
            foreach (collect($contracts)->chunk(self::CONTRACTS_PER_SIGNAL_UNIT) as $chunk) {
                if ($signals->currentChain() === []) {
                    $signals->startFresh();
                }

                foreach ($chunk as $contract) {
                    $this->execute($contract, $version);
                }
            }
        });
    }
}
