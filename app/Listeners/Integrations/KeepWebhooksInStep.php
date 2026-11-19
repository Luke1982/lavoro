<?php

namespace App\Listeners\Integrations;

use App\Domain\Integrations\Sync\Webhooks;
use App\Domain\Signals\ModelChanged;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A connection switched on, off, or to read another area asks its system for
 * the webhooks that now fit. Removing a connection unsubscribes before the
 * delete, in IntegrationController, because afterwards the webhooks are gone.
 */
class KeepWebhooksInStep implements ShouldHandleEventsAfterCommit
{
    public function __construct(private Webhooks $webhooks) {}

    public function handle(ModelChanged $signal): void
    {
        if (!$signal->model instanceof IntegrationConnection || !in_array($signal->action, ['created', 'updated'], true)) {
            return;
        }

        $this->webhooks->ensure($signal->model);
    }
}
