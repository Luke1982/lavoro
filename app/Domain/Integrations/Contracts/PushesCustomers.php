<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Data\CustomerData;
use App\Domain\Integrations\Data\ExternalRecord;

/**
 * The other side takes customers from Lavoro, new and changed. Both return the
 * customer as it now stands there, so the next pull can tell our own change
 * from a new one of theirs.
 *
 * Only the fields the connector carries are sent; everything else on their
 * record stays as it is. An update of a record that is gone there throws
 * RecordNotFound, so the link is marked as such instead of failing every time.
 */
interface PushesCustomers
{
    public function createCustomer(CustomerData $customer): ExternalRecord;

    public function updateCustomer(string $id, CustomerData $customer): ExternalRecord;
}
