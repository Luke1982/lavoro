<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Image;
use Illuminate\Database\Eloquent\Model;

/**
 * A photo on a project was shared with the work orders of that project, where it
 * shows among their internal photos, or no longer is. The photo itself stays on
 * the project either way.
 */
class ImageShareChanged extends BaseSignal
{
    public function __construct(
        public Model $source,
        public Image $image,
        public bool $shared,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'image.share_changed';
    }

    public static function label(): string
    {
        return 'Delen van afbeelding gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'image';
    }

    public function subject(): Model
    {
        return $this->source;
    }

    public function activityDescription(): ?string
    {
        return 'Afbeelding "' . $this->image->name . '" '
            . ($this->shared ? 'gedeeld' : 'niet meer gedeeld')
            . ' met de werkbonnen';
    }

    public function activityMetadata(): ?array
    {
        return [
            'thumbnail_path' => $this->image->path,
            'thumbnail_image_id' => $this->image->id,
        ];
    }

    public function changes(): array
    {
        return [[
            'field' => 'shared',
            'label' => 'Gedeeld met de werkbonnen',
            'old_value' => $this->shared ? '0' : '1',
            'new_value' => $this->shared ? '1' : '0',
            'old_label' => $this->shared ? 'Nee' : 'Ja',
            'new_label' => $this->shared ? 'Ja' : 'Nee',
        ]];
    }
}
