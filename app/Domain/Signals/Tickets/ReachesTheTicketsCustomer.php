<?php

namespace App\Domain\Signals\Tickets;

use App\Models\Ticket;

/**
 * What happens to a storing is news at the customer whose machine it is on too,
 * where it is read under the storing's name.
 *
 * @property Ticket $ticket
 */
trait ReachesTheTicketsCustomer
{
    public function activityContext(): array
    {
        return array_values(array_filter([$this->ticket->asset?->resolvedCustomer()]));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function withTicketLabel(array $metadata = []): array
    {
        return ['subject_label' => $this->ticket->historyLabel(), ...$metadata];
    }
}
