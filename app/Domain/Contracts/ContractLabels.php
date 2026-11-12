<?php

namespace App\Domain\Contracts;

use App\Enums\ContractInterval;
use App\Models\Asset;
use App\Models\MaintenanceContract;

/**
 * How a contract's machines and frequencies are named on its timeline.
 *
 * The contract screen and the assistant both write these into the same trail,
 * and a machine that reads "Daikin FTXM25 (K1234)" when attached by hand and
 * "#812" when attached by the assistant is one machine with two histories.
 */
final class ContractLabels
{
    public static function asset(Asset $asset): string
    {
        $asset->loadMissing('product.brand');
        $name = trim(implode(' ', array_filter([
            $asset->product?->brand?->name,
            $asset->product?->model,
        ])));

        if ($asset->serial_number) {
            return $name !== '' ? "{$name} ({$asset->serial_number})" : $asset->serial_number;
        }

        return $name !== '' ? $name : ('#' . $asset->id);
    }

    public static function frequency(?string $frequency, ?int $frequency_days): string
    {
        if (!$frequency) {
            return 'onbekend';
        }

        if ($frequency === ContractInterval::aangepast->value && $frequency_days) {
            return $frequency . ' (' . $frequency_days . ' dagen)';
        }

        return $frequency;
    }

    /**
     * How often this contract will generate work orders, read off the contract
     * itself rather than off the request: no interval of its own means it follows
     * the contract frequency, and only the saved contract knows which it is.
     */
    public static function autoGeneration(MaintenanceContract $contract): string
    {
        $interval = $contract->auto_generate_interval?->value;

        return $interval
            ? self::frequency($interval, $contract->auto_generate_interval_days)
            : 'contractfrequentie';
    }
}
