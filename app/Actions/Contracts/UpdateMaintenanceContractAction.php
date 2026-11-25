<?php

namespace App\Actions\Contracts;

use App\Domain\Contracts\ContractLabels;
use App\Domain\Signals\Contracts\ContractAutoGenerationDisabled;
use App\Domain\Signals\Contracts\ContractAutoGenerationEnabled;
use App\Domain\Signals\Contracts\ContractCancelled;
use App\Domain\Signals\Contracts\ContractCustomerChanged;
use App\Domain\Signals\Contracts\ContractFrequencyModeChanged;
use App\Domain\Signals\Contracts\ContractReactivated;
use App\Domain\Signals\Signals;
use App\Models\Customer;
use App\Models\MaintenanceContract;
use App\Services\AssetTransferService;
use Illuminate\Support\Facades\DB;

/**
 * Changes a maintenance contract the way its screen does: cancelling or
 * reactivating it, handing it to another customer with or without its
 * machines, switching between one frequency and one per machine, and turning
 * automatic werkbonnen on or off, each with the signal that tells the trail.
 *
 * The attributes are whatever MaintenanceContractUpdateRequest's rules let
 * through: that request defines the shape, for the form and the assistant alike.
 */
class UpdateMaintenanceContractAction
{
    public function __construct(
        private readonly DetachContractAssetAction $detach,
        private readonly AssetTransferService $transfer,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(MaintenanceContract $contract, array $validated): void
    {
        $was_contract_wide = !$contract->manage_frequency_per_asset;
        $original = $contract->getAttributes();

        $asset_strategy = $validated['asset_strategy'] ?? null;
        $location_map = $validated['location_map'] ?? [];
        unset($validated['asset_strategy'], $validated['location_map']);

        $transfer_roots = $asset_strategy === 'transfer'
            ? $contract->assets->map->rootAsset()->unique('id')->values()
            : collect();

        if (array_key_exists('cancelled', $validated)) {
            $was_cancelled_at = $contract->cancelled_at;
            $is_already_cancelled = $was_cancelled_at !== null;

            if ($validated['cancelled'] && !$is_already_cancelled) {
                $contract->cancelled_at = now();
                $contract->save();
                Signals::dispatch(new ContractCancelled($contract));
            } elseif (!$validated['cancelled'] && $is_already_cancelled) {
                $contract->cancelled_at = null;
                $contract->save();
                Signals::dispatch(new ContractReactivated($contract, $was_cancelled_at));
            }
        }

        DB::transaction(function () use ($contract, $validated, $asset_strategy) {
            if ($asset_strategy === 'detach') {
                foreach ($contract->assets as $asset) {
                    $this->detach->execute($contract, $asset->pivot->id);
                }
            }

            $contract->update($validated);
        });

        /**
         * Ordered deliberately: the contract already belongs to the new customer by the
         * time the machines move, so the transfer sees it as a contract of the new owner
         * and leaves its attachments alone. Only the old customer's other contracts lose
         * the machines.
         */
        if ($transfer_roots->isNotEmpty()) {
            $this->transfer->transfer(
                $transfer_roots,
                $contract->customer_id,
                $location_map
            );
        }

        $switched_to_individual = array_key_exists('manage_frequency_per_asset', $validated)
            && $validated['manage_frequency_per_asset']
            && $was_contract_wide;

        if ($switched_to_individual) {
            $contract->assets()
                ->newPivotQuery()
                ->whereNull('frequency')
                ->update([
                    'frequency' => $validated['frequency'] ?? $contract->getRawOriginal('frequency'),
                    'frequency_days' => $validated['frequency_days'] ?? $contract->frequency_days,
                ]);
            Signals::dispatch(new ContractFrequencyModeChanged($contract, per_asset: true));
        } elseif (array_key_exists('manage_frequency_per_asset', $validated) && !$validated['manage_frequency_per_asset']) {
            Signals::dispatch(new ContractFrequencyModeChanged($contract, per_asset: false));
        }

        if (array_key_exists('auto_generate', $validated)) {
            $was_auto = (bool) ($original['auto_generate'] ?? false);
            $will_be_auto = (bool) $validated['auto_generate'];

            if ($will_be_auto && !$was_auto) {
                Signals::dispatch(new ContractAutoGenerationEnabled(
                    $contract,
                    ContractLabels::autoGeneration($contract)
                ));
            } elseif (!$will_be_auto && $was_auto) {
                Signals::dispatch(new ContractAutoGenerationDisabled($contract));
            }
        }

        $this->announceCustomerChange($contract, $validated, $original);
    }

    /**
     * Only the customer is told here: every other field is written by the
     * contract's own trail, with its label and its permission.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $original
     */
    private function announceCustomerChange(MaintenanceContract $contract, array $validated, array $original): void
    {
        if (array_key_exists('customer_id', $validated) && (string) $validated['customer_id'] !== (string) ($original['customer_id'] ?? '')) {
            $old_customer = Customer::find($original['customer_id'] ?? null)?->name ?? 'onbekend';
            $new_customer = Customer::find($validated['customer_id'])?->name ?? 'onbekend';
            Signals::dispatch(new ContractCustomerChanged($contract, $old_customer, $new_customer));
        }
    }
}
