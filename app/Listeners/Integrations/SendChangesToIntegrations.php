<?php

namespace App\Listeners\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Signals\ModelChanged;
use App\Jobs\Integrations\PushRecordJob;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A record that was made or changed here goes to every linked system that
 * takes that kind of record. Nothing that saves a customer needs to know a
 * linked system exists.
 *
 * A change that itself came from a linked system is sent too, and that is not
 * a loop: the job finds the record identical to what that system last had and
 * does nothing, while the other systems do get it.
 *
 * Werkbonnen are not sent on every change: they go when someone presses the
 * button, through ServiceOrderSender.
 */
class SendChangesToIntegrations implements ShouldHandleEventsAfterCommit
{
    public function handle(ModelChanged $signal): void
    {
        if (!in_array($signal->action, ['created', 'updated', 'restored'], true)) {
            return;
        }

        $area = Area::forModel($signal->model);

        if (!$area || $area === Area::ServiceOrders) {
            return;
        }

        foreach (IntegrationConnection::writing($area) as $connection) {
            PushRecordJob::dispatch($connection->id, $area->value, $signal->model->getKey());
        }
    }
}
