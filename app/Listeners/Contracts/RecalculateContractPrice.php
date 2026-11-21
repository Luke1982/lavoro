<?php

namespace App\Listeners\Contracts;

use App\Actions\Contracts\RecalculateContractPriceAction;
use App\Domain\Signals\Contracts\ContractAssetAttached;
use App\Domain\Signals\Contracts\ContractAssetDetached;
use App\Domain\Signals\Contracts\ContractAssetDetachedByTransfer;
use App\Domain\Signals\Contracts\ContractAssetFrequencyChanged;
use App\Domain\Signals\Contracts\ContractFrequencyModeChanged;
use App\Domain\Signals\ModelChanged;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use Illuminate\Support\Facades\DB;

/**
 * A contract with a price formula is priced by its machines and by its own
 * terms, so its price follows both: a machine coming on or going off, moving to
 * another address or becoming another model, and the contract's price interval,
 * dates or maintenance frequency changing, which the formula can count with.
 * A contract without a formula keeps the price somebody typed in.
 */
class RecalculateContractPrice
{
    private const PRICED_MACHINE_FIELDS = ['product_id', 'location_id'];

    private const PRICED_CONTRACT_FIELDS = [
        'price_interval', 'price_interval_days', 'start_date', 'end_date', 'frequency', 'frequency_days',
        'auto_generate_interval', 'auto_generate_interval_days',
    ];

    public function __construct(private readonly RecalculateContractPriceAction $recalculate) {}

    public function handleAttached(ContractAssetAttached $signal): void
    {
        $this->recalculate->execute($signal->contract);
    }

    /** A contract being deleted has no price left to keep. */
    public function handleDetached(ContractAssetDetached $signal): void
    {
        if ($signal->contract_deleted) {
            return;
        }

        $this->recalculate->execute($signal->contract);
    }

    public function handleDetachedByTransfer(ContractAssetDetachedByTransfer $signal): void
    {
        $this->recalculate->execute($signal->contract);
    }

    public function handleFrequencyChanged(ContractAssetFrequencyChanged $signal): void
    {
        $this->recalculate->execute($signal->contract);
    }

    public function handleFrequencyModeChanged(ContractFrequencyModeChanged $signal): void
    {
        $this->recalculate->execute($signal->contract);
    }

    /**
     * Read off the model rather than off the trail's list of changes: a field
     * another signal reports is left out of that list, and would stop repricing.
     */
    public function handleRecordChanged(ModelChanged $signal): void
    {
        if ($signal->model instanceof MaintenanceContract && $signal->model->wasChanged(self::PRICED_CONTRACT_FIELDS)) {
            $this->recalculate->execute($signal->model);

            return;
        }

        if (!$signal->model instanceof Asset || !$signal->model->wasChanged(self::PRICED_MACHINE_FIELDS)) {
            return;
        }

        $contract_ids = DB::table('assetables')
            ->where('asset_id', $signal->model->getKey())
            ->where('assetable_type', MaintenanceContract::class)
            ->pluck('assetable_id');

        MaintenanceContract::whereKey($contract_ids)
            ->whereNotNull('price_formula_version_id')
            ->get()
            ->each(fn (MaintenanceContract $contract) => $this->recalculate->execute($contract));
    }
}
