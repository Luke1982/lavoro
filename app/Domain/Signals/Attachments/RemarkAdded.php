<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Remark;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone placed a remark on a record, for colleagues only or for the customer to
 * see as well. The text is carried along, so the trail still says what was said
 * after the remark itself is gone.
 */
class RemarkAdded extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    public function __construct(
        public Model $record,
        public Remark $remark,
        public bool $internal = false,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'remark.added';
    }

    public static function label(): string
    {
        return 'Opmerking geplaatst';
    }

    public function activityCategory(): string
    {
        return 'comment';
    }

    public function activityDescription(): ?string
    {
        return ($this->internal ? 'Interne opmerking geplaatst' : 'Opmerking geplaatst')
            . $this->atCheckpoint() . ': ' . $this->remark->content;
    }

    public function activityMetadata(): ?array
    {
        return $this->withCheckpoint([
            'remark_id' => $this->remark->id,
            'internal' => $this->internal,
        ]);
    }
}
