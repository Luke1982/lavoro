<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;

class ImageRemoved extends BaseSignal
{
    public function __construct(
        public Model $record,
        public int $image_id,
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

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityDescription(): ?string
    {
        return 'Afbeelding verwijderd';
    }
}
