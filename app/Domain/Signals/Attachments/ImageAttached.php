<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;

class ImageAttached extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    public function __construct(
        public Model $record,
        public int $count,
        public ?string $thumbnail_path,
        public ?int $thumbnail_image_id = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'image.attached';
    }

    public static function label(): string
    {
        return 'Afbeelding toegevoegd';
    }

    public function activityCategory(): string
    {
        return 'image';
    }

    public function activityDescription(): ?string
    {
        return ($this->count === 1 ? 'Afbeelding toegevoegd' : $this->count . ' afbeeldingen toegevoegd')
            . $this->atCheckpoint();
    }

    /**
     * Het pad wordt sinds jaar en dag meegegeven aan deze klasse en kwam nooit
     * verder dan hier: de tijdlijn leest metadata.thumbnail_path en die werd nergens
     * geschreven, dus het voorbeeldplaatje bleef overal weg.
     *
     * Het id staat erbij en niet alleen het pad, omdat afbeeldingen straks per
     * huurder achter een eigen route komen te staan en /storage/ dan niets meer
     * oplevert. Met het id erin is dat later een aanpassing in de tijdlijn en niet
     * opnieuw een aanpassing hier.
     */
    public function activityMetadata(): ?array
    {
        return $this->withCheckpoint([
            'count' => $this->count,
            'thumbnail_path' => $this->thumbnail_path,
            'thumbnail_image_id' => $this->thumbnail_image_id,
        ]);
    }
}
