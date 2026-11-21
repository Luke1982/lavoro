<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Image;
use Illuminate\Database\Eloquent\Model;

/**
 * A photo was swapped for a new one under the same title. Unlike a drawing on it,
 * the photo as it was is not kept: what people see from now on is another picture.
 */
class ImageReplaced extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    public function __construct(
        public Model $record,
        public Image $image,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'image.replaced';
    }

    public static function label(): string
    {
        return 'Afbeelding vervangen';
    }

    public function activityCategory(): string
    {
        return 'image';
    }

    public function activityDescription(): ?string
    {
        return 'Afbeelding "' . $this->image->name . '" vervangen door een nieuwe foto' . $this->atCheckpoint();
    }

    public function activityMetadata(): ?array
    {
        return $this->withCheckpoint([
            'image_id' => $this->image->id,
            'thumbnail_path' => $this->image->path,
            'thumbnail_image_id' => $this->image->id,
        ]);
    }
}
