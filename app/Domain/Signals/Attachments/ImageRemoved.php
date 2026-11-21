<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;

class ImageRemoved extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    public function __construct(
        public Model $record,
        public int $image_id,
        public ?string $image_name = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'image.removed';
    }

    public static function label(): string
    {
        return 'Afbeelding verwijderd';
    }

    public function activityCategory(): string
    {
        return 'image';
    }

    public function activityDescription(): ?string
    {
        $image = $this->image_name === null ? 'Afbeelding' : 'Afbeelding "' . $this->image_name . '"';

        return $image . ' verwijderd' . $this->atCheckpoint();
    }

    public function activityMetadata(): ?array
    {
        return $this->withCheckpoint(['image_id' => $this->image_id]);
    }
}
