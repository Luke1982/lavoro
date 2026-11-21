<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Document;
use Illuminate\Database\Eloquent\Model;

/**
 * One or more documents were filed on a record at once, from the upload on the
 * screen, by the assistant or by a macro: one entry per upload, naming every
 * file, the way photos are told. A customer's own upload through the link is told
 * by CustomerInfoUploaded instead, once for everything that came in.
 */
class DocumentAttached extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    /**
     * @param  array<int, Document>  $documents
     */
    public function __construct(
        public Model $record,
        public array $documents,
        public bool $internal = false,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'document.attached';
    }

    public static function label(): string
    {
        return 'Document toegevoegd';
    }

    public function activityCategory(): string
    {
        return 'document';
    }

    public function activityDescription(): ?string
    {
        $count = count($this->documents);

        if ($count === 0) {
            return null;
        }

        $what = $count === 1
            ? ($this->internal ? 'Intern document' : 'Document')
            : $count . ($this->internal ? ' interne documenten' : ' documenten');

        return $what . ' toegevoegd' . $this->atCheckpoint() . ': ' . $this->names();
    }

    /**
     * The first document keeps the keys a single upload always had, so older and
     * newer entries read the same; the batch is listed beside it.
     */
    public function activityMetadata(): ?array
    {
        $first = $this->documents[0] ?? null;

        return $this->withCheckpoint([
            'document_id' => $first?->id,
            'name' => $first?->name,
            'title' => $first?->title,
            'category' => $first?->category?->name,
            'internal' => $this->internal,
            'count' => count($this->documents),
            'documents' => array_map(fn (Document $document) => [
                'id' => $document->id,
                'name' => $document->name,
            ], $this->documents),
        ]);
    }

    private function names(): string
    {
        return implode(', ', array_map(fn (Document $document) => $document->name, $this->documents));
    }
}
