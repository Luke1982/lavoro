<?php

namespace App\Domain\Signals\Contracts;

use App\Models\MaintenanceContract;

/**
 * What changes a contract's standing is news at its customer too, where it is
 * read under the contract's name.
 *
 * @property MaintenanceContract $contract
 */
trait ReachesTheContractsCustomer
{
    use NamesTheContract;

    public function activityContext(): array
    {
        return array_values(array_filter([$this->contract->customer]));
    }
}
