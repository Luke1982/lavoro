<?php

namespace App\Domain\Assistant\Evals;

/**
 * Every scenario the eval knows, listed by hand like the tools are.
 *
 * Fixtures holding real customer data live in storage/assistant-evals/fixtures,
 * which is not in git; a scenario whose file or records are missing here is
 * skipped, not failed.
 */
final class EvalScenarios
{
    /** @return array<int, EvalScenario> */
    public static function all(): array
    {
        return [
            ...PlanningScenarios::all(),
            ...PricingScenarios::all(),
            ...QuoteScenarios::all(),
        ];
    }
}
