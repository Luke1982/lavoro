<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;

class RemarkRemoved extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    public function __construct(
        public Model $record,
        public int $remark_id,
        public string $content,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'remark.removed';
    }

    public static function label(): string
    {
        return 'Opmerking verwijderd';
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    public function activityDescription(): ?string
    {
        return 'Opmerking verwijderd' . $this->atCheckpoint() . ': ' . $this->content;
    }

    public function activityMetadata(): ?array
    {
        return $this->withCheckpoint();
    }
}
