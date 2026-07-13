<?php

namespace App\Services;

use App\Enums\ContractInterval;
use App\Enums\ServiceJobOutcomes;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Carbon\Carbon;

class MaintenanceContractServiceOrderGenerator
{
    /**
     * @return array<int, ServiceOrder>
     */
    public function generateAllDue(): array
    {
        $created = [];

        MaintenanceContract::query()
            ->where('auto_generate', true)
            ->whereNull('cancelled_at')
            ->where('start_date', '<=', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))
            ->each(function (MaintenanceContract $contract) use (&$created) {
                $created = array_merge($created, $this->generateDueForContract($contract));
            });

        return $created;
    }

    /**
     * @return array<int, ServiceOrder>
     */
    public function generateDueForContract(MaintenanceContract $contract): array
    {
        if ($contract->status !== 'actief') {
            return [];
        }

        $due_assets = [];

        foreach ($contract->assets as $asset) {
            $pivot = $asset->pivot;
            [$frequency, $frequency_days] = $this->effectiveFrequency($contract, $pivot);

            if (!$frequency) {
                continue;
            }

            $next_due = $pivot->last_generated_at
                ? $this->nextDueDate(Carbon::parse($pivot->last_generated_at), $frequency, $frequency_days)
                : Carbon::parse($contract->start_date);

            if ($next_due->gt(Carbon::today())) {
                continue;
            }

            $due_assets[] = $asset;
        }

        if (empty($due_assets)) {
            return [];
        }

        return [$this->createServiceOrderForAssets($contract, $due_assets, 'automatisch gegenereerd')];
    }

    /**
     * Creates one werkbon right now for every machine on the contract, ignoring due dates.
     */
    public function generateNowForContract(MaintenanceContract $contract): ?ServiceOrder
    {
        $assets = $contract->assets->all();

        if (empty($assets)) {
            return null;
        }

        return $this->createServiceOrderForAssets($contract, $assets, 'handmatig aangemaakt');
    }

    /**
     * @param  array<int, Asset>  $assets
     */
    private function createServiceOrderForAssets(
        MaintenanceContract $contract,
        array $assets,
        string $activity_verb
    ): ServiceOrder {
        $service_order = ServiceOrder::create([
            'customer_id' => $contract->customer_id,
            'maintenance_contract_id' => $contract->id,
        ]);

        $labels = [];

        foreach ($assets as $asset) {
            ServiceJob::create([
                'asset_id' => $asset->id,
                'service_order_id' => $service_order->id,
                'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value,
            ]);

            $contract->assets()->newPivotQuery()->where('assetables.id', $asset->pivot->id)->update([
                'last_generated_at' => now(),
            ]);

            $labels[] = $asset->serial_number ?? ('#' . $asset->id);
        }

        $contract->logActivity(sprintf(
            'Werkbon #%d %s voor %s',
            $service_order->id,
            $activity_verb,
            implode(', ', $labels)
        ));

        return $service_order;
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function effectiveFrequency(MaintenanceContract $contract, $pivot): array
    {
        if ($contract->auto_generate_interval) {
            $interval = $contract->auto_generate_interval instanceof ContractInterval
                ? $contract->auto_generate_interval->value
                : $contract->auto_generate_interval;

            return [$interval, $contract->auto_generate_interval_days];
        }

        if ($contract->manage_frequency_per_asset) {
            return [$pivot->frequency, $pivot->frequency_days];
        }

        $frequency = $contract->frequency instanceof ContractInterval ? $contract->frequency->value : $contract->frequency;

        return [$frequency, $contract->frequency_days];
    }

    private function nextDueDate(Carbon $anchor, string $frequency, ?int $frequency_days): Carbon
    {
        return match ($frequency) {
            ContractInterval::maandelijks->value => $anchor->copy()->addMonth(),
            ContractInterval::halfjaarlijks->value => $anchor->copy()->addMonths(6),
            ContractInterval::jaarlijks->value => $anchor->copy()->addYear(),
            ContractInterval::aangepast->value => $anchor->copy()->addDays($frequency_days ?? 0),
            default => $anchor->copy()->addYears(100),
        };
    }
}
