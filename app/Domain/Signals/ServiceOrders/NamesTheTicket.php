<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Models\Asset;
use App\Models\Ticket;

/**
 * A storing put on or taken off a werkbon, named by its subject and by the machine
 * it is about when it names one, so the werkbon says which storing came or went.
 *
 * @property Ticket $ticket
 * @property ?Asset $asset
 */
trait NamesTheTicket
{
    protected function ticketLabel(): string
    {
        $label = $this->ticket->subject ?: 'Storing #' . $this->ticket->id;

        if (!$this->asset) {
            return $label;
        }

        return sprintf(
            '%s (%s %s %s, serienummer %s)',
            $label,
            $this->asset->product?->productType?->name ?? 'Type',
            $this->asset->product?->brand?->name ?? 'Merk',
            $this->asset->product?->model ?? '',
            $this->asset->serial_number ?? '-'
        );
    }
}
