<?php

namespace App\Actions\ServiceOrders;

/**
 * Everything needed to open a werkbon, however the request arrived.
 *
 * @property array<int, int> $asset_ids Machines to put on it as service jobs.
 * @property array<int, int> $ticket_ids Storingen to hang off it.
 */
final class NewServiceOrder
{
    /**
     * @param  array<int, int>  $asset_ids
     * @param  array<int, int>  $ticket_ids
     */
    public function __construct(
        public readonly int $customer_id,
        public readonly ?int $project_id = null,
        public readonly ?int $location_id = null,
        public readonly ?string $description = null,
        public readonly array $asset_ids = [],
        public readonly array $ticket_ids = [],
    ) {}
}
