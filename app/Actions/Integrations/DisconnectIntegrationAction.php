<?php

namespace App\Actions\Integrations;

use App\Domain\Integrations\Sync\Webhooks;
use App\Models\IntegrationConnection;

/**
 * Removes a connection. The links, the run log and the webhooks go with it;
 * the records do not: customers and materials stay in Lavoro, and in the other
 * system, as they are.
 *
 * The webhooks are cancelled there first. Once the connection is deleted its
 * webhooks are gone here, and nothing would know what to cancel.
 */
class DisconnectIntegrationAction
{
    public function __construct(private Webhooks $webhooks) {}

    public function execute(IntegrationConnection $connection): void
    {
        $this->webhooks->removeAll($connection);
        $connection->delete();
    }
}
