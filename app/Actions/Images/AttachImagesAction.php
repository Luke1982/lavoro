<?php

namespace App\Actions\Images;

use App\Domain\Signals\Attachments\ImageAttached;
use App\Domain\Signals\Signals;
use App\Models\Image;
use Illuminate\Database\Eloquent\Model;

/**
 * Puts new photos among the photos of a record, on the side it names, and tells
 * the record once, naming how many came in. Shared by the upload, the import from
 * an address and pasting copies off the bakje, so a photo arrives the same way
 * whichever door it came through.
 */
class AttachImagesAction
{
    /** The folder a record's photos are kept in. */
    public static function folderFor(Model $record): string
    {
        return 'uploaded/' . strtolower(class_basename($record)) . '/' . $record->getKey();
    }

    /**
     * @param  Model  $record  Anything with an images() morph relation.
     * @param  array<int, array<string, mixed>>  $images  The columns of each new photo: its name and file, and a drawing it brings along.
     * @return array<int, Image>
     */
    public function execute(Model $record, array $images, bool $internal = false): array
    {
        $attached = array_map(function (array $columns) use ($record, $internal) {
            $image = Image::create($columns);
            $record->images()->attach($image->id, ['internal' => $internal]);

            return $image;
        }, $images);

        if ($attached !== []) {
            Signals::dispatch(new ImageAttached($record, count($attached), $attached[0]->path, $attached[0]->id));
        }

        return $attached;
    }
}
