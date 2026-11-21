<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Document;
use Illuminate\Database\Eloquent\Model;

/**
 * A document was taken off the record it was filed on, and deleted. Carries the
 * file's path so its file can go once the deletion has committed.
 */
class DocumentRemoved extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    public function __construct(
        public Model $record,
        public int $document_id,
        public string $document_name,
        public ?string $path = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'document.removed';
    }

    public static function label(): string
    {
        return 'Document verwijderd';
    }

    public function activityCategory(): string
    {
        return 'document';
    }

    /**
     * A document that hung off nothing is announced on itself, only so that its
     * file goes: no screen shows that, so it writes no entry.
     */
    public function activityDescription(): ?string
    {
        return $this->record instanceof Document
            ? null
            : 'Document verwijderd' . $this->atCheckpoint() . ': ' . $this->document_name;
    }

    public function activityMetadata(): ?array
    {
        return $this->withCheckpoint();
    }
}
