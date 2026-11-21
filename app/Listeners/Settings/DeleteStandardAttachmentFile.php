<?php

namespace App\Listeners\Settings;

use App\Domain\Signals\ModelChanged;
use App\Models\StandardAttachment;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Storage;

/**
 * A removed standard attachment takes its file along, once the removal is
 * final: before the commit the file would be gone while a removal that fails
 * still points at it. Nothing restores a removed attachment, so the file is not
 * kept for that either; it would only count against the storage allowance.
 */
class DeleteStandardAttachmentFile implements ShouldHandleEventsAfterCommit
{
    public function handle(ModelChanged $signal): void
    {
        $attachment = $signal->model;

        if ($signal->action !== 'deleted' || !$attachment instanceof StandardAttachment || !$attachment->path) {
            return;
        }

        Storage::disk('public')->delete($attachment->path);
    }
}
