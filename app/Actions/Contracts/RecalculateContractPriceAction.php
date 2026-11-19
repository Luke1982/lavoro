<?php

namespace App\Actions\Contracts;

use App\Domain\Pricing\ContractPricing;
use App\Domain\Signals\Contracts\ContractPriceRecalculated;
use App\Domain\Signals\Signals;
use App\Models\MaintenanceContract;

/**
 * Puts the price its formula version gives on a contract, and says so on its
 * timeline when that is a different price than it had.
 *
 * A contract that has ended keeps the price it ended with: a machine moving on
 * afterwards does not rewrite what was agreed. Only changing its formula or its
 * inputs reprices it, because then somebody asked for exactly that.
 */
class RecalculateContractPriceAction
{
    private const MAX_CENTS = 9_999_999_999;

    public function __construct(private readonly ContractPricing $pricing) {}

    public function execute(MaintenanceContract $contract, bool $even_if_ended = false): void
    {
        if (!$even_if_ended && $contract->hasEnded()) {
            return;
        }

        $result = $this->pricing->evaluate($contract);

        if ($result === null) {
            return;
        }

        $previous_price = $this->cents($contract->getRawOriginal('price'));
        $previous_one_time = $this->cents($contract->getRawOriginal('one_time_price'));

        $new_price = $this->storable($result['periodic'] ?? 0);
        $new_one_time = $result['one_time'] === null ? null : $this->storable($result['one_time']);

        if ($new_price === $previous_price && $new_one_time === $previous_one_time) {
            return;
        }

        /**
         * Saved without the automatic trail: the signal below reports both fields,
         * and says why they changed, which the automatic entry cannot.
         */
        $contract->forceFill([
            'price' => $new_price / 100,
            'one_time_price' => $new_one_time === null ? null : $new_one_time / 100,
        ])->saveQuietly();

        Signals::dispatch(new ContractPriceRecalculated(
            $contract,
            $previous_price,
            $new_price,
            $previous_one_time,
            $new_one_time,
        ));
    }

    /**
     * A formula can come out below nothing or beyond what the column holds; the
     * contract gets the nearest price it can carry, and the formula's own page
     * shows what it really came to.
     */
    private function storable(float|int $amount): int
    {
        if (!is_finite((float) $amount)) {
            return 0;
        }

        return (int) max(0.0, min((float) self::MAX_CENTS, round($amount * 100)));
    }

    private function cents(mixed $amount): ?int
    {
        return $amount === null ? null : (int) round(((float) $amount) * 100);
    }
}
