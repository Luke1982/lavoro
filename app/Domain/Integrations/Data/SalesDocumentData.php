<?php

namespace App\Domain\Integrations\Data;

use Carbon\CarbonImmutable;

/**
 * A werkbon in the shape every connector speaks: who it is for, what is on it,
 * and the reference it is known by here. What it becomes there -- a sales order,
 * a draft or a final invoice -- is chosen separately (DocumentForm).
 */
class SalesDocumentData
{
    /**
     * @param  array<int, SalesLineData>  $lines
     * @param  array<int, string>  $warnings  What was left off before it reached the connector.
     */
    public function __construct(
        public string $reference,
        public string $description,
        public CarbonImmutable $date,
        public string $customer_id,
        public CustomerData $customer,
        public array $lines,
        public ?string $memo = null,
        public array $warnings = [],
    ) {}
}
