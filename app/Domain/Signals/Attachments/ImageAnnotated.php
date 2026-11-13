<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Image;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone drew on, wrote on or cropped a photo. The photo as it was taken is kept,
 * so this records who changed what people see, not that something was lost.
 */
class ImageAnnotated extends BaseSignal
{
    public function __construct(
        public Model $record,
        public Image $image,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'image.annotated';
    }

    public static function label(): string
    {
        return 'Afbeelding bewerkt';
    }

    public function activityCategory(): string
    {
        return 'image';
    }

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityDescription(): ?string
    {
        return 'Afbeelding "' . $this->image->name . '" bewerkt';
    }

    public function activityMetadata(): ?array
    {
        return [
            'image_id' => $this->image->id,
        ];
    }
}
