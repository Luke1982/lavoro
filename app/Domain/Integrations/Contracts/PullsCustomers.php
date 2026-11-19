<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\ChangeDetection;
use App\Domain\Integrations\Data\ExternalRecord;
use Carbon\CarbonImmutable;

/**
 * The other side can hand over its customers.
 */
interface PullsCustomers
{
    public static function customerChanges(): ChangeDetection;

    /**
     * Every customer, or with Delta only those changed since $since. Each
     * record's data is a CustomerData.
     *
     * @return iterable<int, ExternalRecord>
     */
    public function pullCustomers(?CarbonImmutable $since = null): iterable;

    /** One customer by its id there, or null when it is gone. */
    public function fetchCustomer(string $id): ?ExternalRecord;
}
