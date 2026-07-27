<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;

class ImageAttached extends BaseSignal
{
    public function __construct(
        public Model $record,
        public int $count,
        public ?string $thumbnail_path,
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

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityDescription(): ?string
    {
        return $this->count === 1 ? 'Afbeelding toegevoegd' : $this->count . ' afbeeldingen toegevoegd';
    }
}
