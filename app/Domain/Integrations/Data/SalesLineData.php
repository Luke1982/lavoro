<?php

namespace App\Domain\Integrations\Data;

/**
 * One line of a werkbon on its way out. The article is the material's id in the
 * receiving system, when the material is linked there; a system that needs an
 * article on every line skips the line without one and says so.
 *
 * The ledger account is the id there of the material's revenue account, when
 * that is linked there. Without one the system books the line the way it
 * always does: on the article's own account, or its default one.
 */
class SalesLineData
{
    public function __construct(
        public string $description,
        public float $quantity,
        public float $unit_price,
        public float $vat_percent,
        public ?string $article_id = null,
        public ?string $code = null,
        public ?string $ledger_account_id = null,
    ) {}

    public function total(): float
    {
        return round($this->quantity * $this->unit_price, 2);
    }
}
