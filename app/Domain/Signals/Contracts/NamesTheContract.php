<?php

namespace App\Domain\Signals\Contracts;

use App\Models\MaintenanceContract;

/**
 * A contract fact read on another record's timeline (its customer's, a machine's)
 * says which contract it is about.
 *
 * @property MaintenanceContract $contract
 */
trait NamesTheContract
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function withContractLabel(array $metadata = []): array
    {
        return ['subject_label' => $this->contract->historyLabel(), ...$metadata];
    }
}
