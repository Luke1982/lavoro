<?php

namespace App\Actions\Images;

use App\Actions\Shares\ShareRecordAction;
use App\Domain\Signals\Attachments\ImageMoved;
use App\Domain\Signals\Signals;
use App\Models\Image;
use App\Models\Share;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moves a photo to another record, or to the other side of the same one. Only
 * its link moves: the photo, its files and its drawing stay as they are, so
 * nothing is copied and nothing is left behind.
 *
 * Shared from where it was, it is no longer shared from there: a share belongs to
 * the record a photo lives on. Where it was, and how, is handed back, so moving
 * it back puts all of that back.
 */
class MoveImageAction
{
    public function __construct(private ShareRecordAction $share) {}

    /**
     * @param  Model  $to  Anything with an images() morph relation.
     * @return array{imageable_type: string, imageable_id: int, internal: bool, main: bool, shared: bool}|null Where it was; null when it hung off nothing.
     */
    public function execute(Image $image, Model $to, bool $internal, bool $main = false, bool $shared = false): ?array
    {
        $link = DB::table('imageables')->where('image_id', $image->id)->orderBy('id')->first();
        $from = $image->attachedRecord();

        if ($link === null || $from === null) {
            return null;
        }

        $was_shared = Share::canShareFrom($from) && $image->shares()
            ->where('source_type', $from->getMorphClass())
            ->where('source_id', $from->getKey())
            ->exists();

        if ($was_shared) {
            $this->share->execute($image, $from, false);
        }

        DB::table('imageables')->where('id', $link->id)->update([
            'imageable_type' => $to->getMorphClass(),
            'imageable_id' => $to->getKey(),
            'internal' => $internal,
            'main' => $main,
            'updated_at' => now(),
        ]);

        Signals::dispatch(new ImageMoved($to, $image, $internal, $from, (bool) $link->internal));

        if ($shared && Share::canShareFrom($to)) {
            $this->share->execute($image, $to, true);
        }

        return [
            'imageable_type' => $from->getMorphClass(),
            'imageable_id' => $from->getKey(),
            'internal' => (bool) $link->internal,
            'main' => (bool) ($link->main ?? false),
            'shared' => $was_shared,
        ];
    }
}
