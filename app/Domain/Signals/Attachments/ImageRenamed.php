<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Image;
use Illuminate\Database\Eloquent\Model;

/**
 * A photo got another title. The photo is the same one; only what it is called changed.
 */
class ImageRenamed extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    public function __construct(
        public Model $record,
        public Image $image,
        public ?string $previous_name,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'image.renamed';
    }

    public static function label(): string
    {
        return 'Afbeelding hernoemd';
    }

    public function activityCategory(): string
    {
        return 'image';
    }

    public function activityDescription(): ?string
    {
        return 'Afbeelding "' . $this->previous_name . '" hernoemd naar "' . $this->image->name . '"'
            . $this->atCheckpoint();
    }

    public function activityMetadata(): ?array
    {
        return $this->withCheckpoint([
            'image_id' => $this->image->id,
            'thumbnail_path' => $this->image->path,
            'thumbnail_image_id' => $this->image->id,
        ]);
    }

    public function changes(): array
    {
        return [[
            'field' => 'image_name',
            'label' => 'Titel afbeelding',
            'old_value' => $this->previous_name,
            'new_value' => $this->image->name,
            'old_label' => $this->previous_name,
            'new_label' => $this->image->name,
        ]];
    }
}
