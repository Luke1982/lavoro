<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Image;
use Illuminate\Database\Eloquent\Model;

/**
 * Another photo became the main photo of a record, or the record no longer has
 * one. The photos themselves stay; only which one is shown first changed.
 */
class MainImageChanged extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    public function __construct(
        public Model $record,
        public ?Image $previous,
        public ?Image $current,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'image.main_changed';
    }

    public static function label(): string
    {
        return 'Hoofdafbeelding gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'image';
    }

    public function activityDescription(): ?string
    {
        if ($this->current === null) {
            return 'Afbeelding "' . $this->previous?->name . '" is niet langer de hoofdafbeelding'
                . $this->atCheckpoint();
        }

        return 'Afbeelding "' . $this->current->name . '" ingesteld als hoofdafbeelding' . $this->atCheckpoint();
    }

    public function activityMetadata(): ?array
    {
        $shown = $this->current ?? $this->previous;

        return $this->withCheckpoint([
            'image_id' => $shown?->id,
            'thumbnail_path' => $shown?->path,
            'thumbnail_image_id' => $shown?->id,
        ]);
    }

    public function changes(): array
    {
        return [[
            'field' => 'main_image',
            'label' => 'Hoofdafbeelding',
            'old_value' => $this->previous?->id !== null ? (string) $this->previous->id : null,
            'new_value' => $this->current?->id !== null ? (string) $this->current->id : null,
            'old_label' => $this->previous?->name,
            'new_label' => $this->current?->name,
        ]];
    }
}
