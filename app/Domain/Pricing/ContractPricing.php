<?php

namespace App\Domain\Pricing;

use App\Enums\ContractInterval;
use App\Models\MaintenanceContract;
use App\Models\PriceFormulaVersion;
use Carbon\Carbon;

/**
 * A contract's price as its formula version makes it: what a recalculation
 * stores, what the contract screen explains, and what a newer version of the
 * formula would make of the same contract.
 */
final class ContractPricing
{
    /** A contract without an end date counts as running for a year, as on the try-out. */
    public const OPEN_ENDED_MONTHS = 12;

    public function __construct(private readonly FormulaEvaluator $evaluator) {}

    /**
     * @return array<string, mixed>|null null for a contract without a formula
     */
    public function evaluate(MaintenanceContract $contract, ?PriceFormulaVersion $version = null): ?array
    {
        $version ??= $contract->priceFormulaVersion;

        if ($version === null) {
            return null;
        }

        return $this->evaluator->evaluate(
            $version->graph,
            PricedMachines::forContract($contract),
            $this->contextFor($contract),
        );
    }

    /**
     * A graph tried out before it is saved: on a real contract, with what is
     * filled in on it unless the trial fills in otherwise, or on made-up machines.
     * The editor's live values and the assistant's checks both come from here.
     *
     * @param  array<int, array<string, mixed>>  $sample
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function trial(
        array $graph,
        ?MaintenanceContract $contract,
        array $sample = [],
        ?string $price_interval = null,
        ?string $frequency = null,
        array $inputs = [],
    ): array {
        if ($contract !== null) {
            $context = $this->contextFor($contract);
            $context['inputs'] = array_replace($context['inputs'], $inputs);

            return [
                ...$this->evaluator->evaluate($graph, PricedMachines::forContract($contract), $context),
                'inputs' => $context['inputs'],
            ];
        }

        return $this->evaluator->evaluate($graph, PricedMachines::forSample($sample, $frequency), [
            'inputs' => $inputs,
            'periods_per_year' => ContractInterval::timesPerYear($price_interval, null),
            'months' => self::OPEN_ENDED_MONTHS,
        ]);
    }

    /** @return array<string, mixed> */
    public function contextFor(MaintenanceContract $contract): array
    {
        $months = self::OPEN_ENDED_MONTHS;

        if ($contract->start_date && $contract->end_date) {
            $end = Carbon::parse($contract->end_date)->addDay();
            $months = (int) round(Carbon::parse($contract->start_date)->diffInMonths($end));
        }

        return [
            'inputs' => $contract->price_formula_inputs ?? [],
            'periods_per_year' => ContractInterval::timesPerYear(
                $contract->price_interval?->value,
                $contract->price_interval_days
            ),
            'months' => $months,
        ];
    }

    /**
     * The blocks of a graph that ask for a number on the contract.
     *
     * @return array<int, array{id: string, name: string, default: float}>
     */
    public static function inputsOf(array $graph): array
    {
        return collect($graph['nodes'] ?? [])
            ->where('type', 'input')
            ->map(fn (array $node) => [
                'id' => (string) $node['id'],
                'name' => trim((string) ($node['data']['name'] ?? '')) ?: 'Invoer',
                'default' => (float) ($node['data']['default'] ?? 0),
            ])
            ->values()
            ->all();
    }
}
