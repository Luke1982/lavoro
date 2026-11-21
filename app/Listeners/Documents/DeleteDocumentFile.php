<?php

namespace App\Listeners\Documents;

use App\Domain\Signals\Attachments\DocumentRemoved;
use App\Models\Document;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Storage;

/**
 * A removed document takes its file along, once the removal has committed: a
 * deletion that rolls back keeps its file. A file another document still points
 * at stays.
 */
class DeleteDocumentFile implements ShouldHandleEventsAfterCommit
{
    public function handle(DocumentRemoved $signal): void
    {
        if ($signal->path === null || Document::where('path', $signal->path)->exists()) {
            return;
        }

        Storage::disk('public')->delete($signal->path);
    }
}
