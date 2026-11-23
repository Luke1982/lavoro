<?php

namespace App\Actions\Images;

use App\Domain\Signals\Attachments\ImageRemoved;
use App\Domain\Signals\Signals;
use App\Models\Image;
use Illuminate\Support\Facades\DB;

/**
 * Removes a photo: its links, the row and, once nothing points at them any more,
 * its files. Shared by the delete on the screen and taking back a paste off the
 * bakje, so both leave the same line on the record's timeline.
 *
 * The record it is announced on is read from the photo's own link: the screen
 * names a record too, but that only says what the person was looking at.
 */
class RemoveImageAction
{
    public function execute(Image $image): void
    {
        $record = $image->attachedRecord();

        DB::table('imageables')->where('image_id', $image->id)->delete();
        $image->delete();

        if ($record !== null) {
            Signals::dispatch(new ImageRemoved($record, $image->id, $image->name));
        }
    }
}
