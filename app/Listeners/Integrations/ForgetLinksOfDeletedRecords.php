<?php

namespace App\Listeners\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Signals\ModelChanged;
use App\Models\Integrationable;

/**
 * A record deleted here keeps its counterpart in the linked system -- a
 * customer that is gone from Lavoro is still in the books -- but loses the
 * link, so the next pull does not stumble over a link to nothing. That pull
 * brings it back as a new record, when the connection reads the area.
 */
class ForgetLinksOfDeletedRecords
{
    public function handle(ModelChanged $signal): void
    {
        if ($signal->action !== 'deleted' || !Area::forModel($signal->model)) {
            return;
        }

        Integrationable::where('integrationable_type', $signal->model->getMorphClass())
            ->where('integrationable_id', $signal->model->getKey())
            ->delete();
    }
}
