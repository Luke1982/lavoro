<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * A document on a record got another title or was filed under another category.
 * Told on the record it hangs from, since a document has no timeline of its own.
 */
class DocumentChanged extends BaseSignal
{
    use ReachesTheRecordsTimeline;

    /** Column on the document => the field and label it is recorded under on the record. */
    private const FIELDS = [
        'title' => ['document_title', 'Titel document'],
        'document_category_id' => ['document_category_id', 'Categorie document'],
    ];

    /**
     * @param  array<string, mixed>  $before  The title and category as they were, by column.
     */
    public function __construct(
        public Model $record,
        public Document $document,
        public array $before = [],
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'document.changed';
    }

    public static function label(): string
    {
        return 'Document gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'document';
    }

    public function activityDescription(): ?string
    {
        return 'Document ' . $this->document->name . ' gewijzigd' . $this->atCheckpoint();
    }

    public function activityMetadata(): ?array
    {
        return $this->withCheckpoint(['document_id' => $this->document->id]);
    }

    public function changes(): array
    {
        $changes = [];

        foreach (self::FIELDS as $field => [$recorded_as, $label]) {
            if (!array_key_exists($field, $this->before)) {
                continue;
            }

            $old = $this->before[$field];
            $new = $this->document->getAttribute($field);

            if ((string) $old === (string) $new) {
                continue;
            }

            $changes[] = [
                'field' => $recorded_as,
                'label' => $label,
                'old_value' => $old === null ? null : (string) $old,
                'new_value' => $new === null ? null : (string) $new,
                'old_label' => $this->display($field, $old),
                'new_label' => $this->display($field, $new),
            ];
        }

        return $changes;
    }

    private function display(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($field === 'document_category_id') {
            return DocumentCategory::find($value)?->name ?? (string) $value;
        }

        return (string) $value;
    }
}
