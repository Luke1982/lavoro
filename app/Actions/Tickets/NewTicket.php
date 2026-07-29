<?php

namespace App\Actions\Tickets;

/**
 * Everything needed to log a storing, however the request arrived.
 *
 * The web form and a tool call describe the same intent, so they describe it
 * with this.
 */
final class NewTicket
{
    public function __construct(
        public readonly int $asset_id,
        public readonly string $subject,
        public readonly string $description,
        public readonly string $status,
        public readonly string $priority,
        public readonly ?int $created_by_id = null,
        public readonly ?int $service_order_id = null,
    ) {}
}
