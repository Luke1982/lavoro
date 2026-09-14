<?php

namespace App\Listeners\Images;

use App\Domain\Signals\ModelChanged;
use App\Jobs\DeleteOrphanedImagesJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * A record that is really gone takes its photos along, and so does everything the
 * database removed with it. The job works that out from what is left, so this only
 * has to notice the deletion. After the commit, or the job could still find the
 * record; a soft-deleted record still exists and keeps its photos.
 *
 * Every hard deletion queues a run, photos or not. Telling which deletions can
 * reach a photo would mean knowing every cascade in the schema, and a run that
 * finds nothing costs a query per type of record that has photos.
 *
 * The queue write sits in a try of its own: the deletion has already committed,
 * and a queue that cannot be reached must not fail it. The nightly run catches up.
 */
class DeleteImagesOfDeletedRecords implements ShouldHandleEventsAfterCommit
{
    public function handle(ModelChanged $signal): void
    {
        if ($signal->action !== 'deleted' || $signal->model->exists) {
            return;
        }

        try {
            DeleteOrphanedImagesJob::dispatch();
        } catch (\Throwable $e) {
            Log::error('Kon het opruimen van foto\'s niet inplannen', [
                'record' => $signal->model->getMorphClass() . '#' . $signal->model->getKey(),
                'exception' => $e,
            ]);
        }
    }
}
