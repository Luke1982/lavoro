<?php

namespace App\Actions\Contracts;

use App\Domain\Pricing\ContractPricing;
use App\Domain\Signals\Contracts\ContractPriceRecalculated;
use App\Domain\Signals\Signals;
use App\Models\MaintenanceContract;
use App\Models\PriceFormulaVersion;
use App\Support\Money;

/**
 * Puts the price its formula version gives on a contract, and says so on its
 * timeline when that is a different price than it had.
 *
 * A contract that has ended keeps the price it ended with: a machine moving on
 * afterwards does not rewrite what was agreed. Only changing its formula or its
 * inputs reprices it, because then somebody asked for exactly that. A formula
 * holding a block this code cannot read prices nothing; the contract keeps its
 * price until the formula can be read again.
 *
 * The price is saved without the automatic trail: the signal reports both
 * fields, and says why they changed, which the automatic entry cannot.
 */
class RecalculateContractPriceAction
{
    private const MAX_CENTS = 9_999_999_999;

    public function __construct(private readonly ContractPricing $pricing) {}

    public function execute(MaintenanceContract $contract, bool $even_if_ended = false): void
    {
        $prices = $this->changedPricesFor($contract, $even_if_ended);

        if ($prices === null) {
            return;
        }

        $previous_price = Money::cents($contract->getRawOriginal('price'));
        $previous_one_time = Money::cents($contract->getRawOriginal('one_time_price'));

        $contract->forceFill([
            'price' => $prices['price'] / 100,
            'one_time_price' => $prices['one_time'] === null ? null : $prices['one_time'] / 100,
        ])->saveQuietly();

        Signals::dispatch(new ContractPriceRecalculated(
            $contract,
            $previous_price,
            $prices['price'],
            $previous_one_time,
            $prices['one_time'],
        ));
    }

    /**
     * What the contract would be priced at, in cents, without saving anything;
     * null when it would not be repriced at all. Pure, so a proposal can ask it
     * of a contract changed only in memory and show exactly the price that
     * saving the change would store. Given a version, it answers for the
     * contract put on that version instead of the one it is on, as moving it
     * there would store.
     *
     * @return ?array{price: int, one_time: ?int}
     */
    public function pricesFor(
        MaintenanceContract $contract,
        bool $even_if_ended = false,
        ?PriceFormulaVersion $version = null,
    ): ?array {
        if (!$even_if_ended && $contract->hasEnded()) {
            return null;
        }

        $result = $this->pricing->evaluate($contract, $version);

        if ($result === null || !$result['readable']) {
            return null;
        }

        return [
            'price' => $this->storable($result['periodic'] ?? 0),
            'one_time' => $result['one_time'] === null ? null : $this->storable($result['one_time']),
        ];
    }

    /**
     * pricesFor(), but only when saving would change what the contract carries
     * now; null when it keeps its price. The one rule for "this reprices", so a
     * proposal says a price changes exactly when the save would store one.
     *
     * @return ?array{price: int, one_time: ?int}
     */
    public function changedPricesFor(MaintenanceContract $contract, bool $even_if_ended = false): ?array
    {
        $prices = $this->pricesFor($contract, $even_if_ended);

        if ($prices === null) {
            return null;
        }

        $unchanged = $prices['price'] === Money::cents($contract->getRawOriginal('price'))
            && $prices['one_time'] === Money::cents($contract->getRawOriginal('one_time_price'));

        return $unchanged ? null : $prices;
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
}
