<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Image;
use App\Models\ServiceCheckInstance;
use Illuminate\Database\Eloquent\Model;

/**
 * A photo went from one record to another, or to the other side of the same
 * one: from the internal photos of a werkbon to the ones the customer sees. The
 * photo itself is the same, only where it hangs moved. Told where it is now and
 * where it was, so both timelines say where it went.
 */
class ImageMoved extends BaseSignal
{
    use ReachesTheRecordsTimeline {
        activityContext as private recordsContext;
    }

    public function __construct(
        public Model $record,
        public Image $image,
        public bool $internal,
        public Model $from,
        public bool $from_internal,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'image.moved';
    }

    public static function label(): string
    {
        return 'Afbeelding verplaatst';
    }

    public function activityCategory(): string
    {
        return 'image';
    }

    /** The timeline of where it was, unless that is the same one: a keurpunt's is its werkbon's. */
    public function activityContext(): array
    {
        $from = $this->from instanceof ServiceCheckInstance ? $this->from->serviceJob?->serviceOrder : $this->from;
        $subject = $this->subject();
        $elsewhere = $from !== null && !($from::class === $subject::class && $from->getKey() === $subject->getKey());

        return array_values(array_filter([...$this->recordsContext(), $elsewhere ? $from : null]));
    }

    public function activityDescription(): ?string
    {
        return 'Afbeelding "' . $this->image->name . '" verplaatst van ' . $this->wasAt() . ' naar ' . $this->isAt();
    }

    public function activityMetadata(): ?array
    {
        return $this->withCheckpoint([
            'thumbnail_path' => $this->image->path,
            'thumbnail_image_id' => $this->image->id,
        ]);
    }

    public function changes(): array
    {
        return [[
            'field' => 'imageable',
            'label' => 'Plek',
            'old_value' => $this->from->getMorphClass() . ':' . $this->from->getKey() . ($this->from_internal ? ':intern' : ''),
            'new_value' => $this->record->getMorphClass() . ':' . $this->record->getKey() . ($this->internal ? ':intern' : ''),
            'old_label' => $this->wasAt(),
            'new_label' => $this->isAt(),
        ]];
    }

    private function wasAt(): string
    {
        return Image::placeOf($this->from, $this->from_internal);
    }

    private function isAt(): string
    {
        return Image::placeOf($this->record, $this->internal);
    }
}
