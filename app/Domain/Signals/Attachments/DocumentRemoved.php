<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use Illuminate\Database\Eloquent\Model;

class DocumentRemoved extends BaseSignal
{
    public function __construct(
        public Model $record,
        public int $document_id,
        public string $document_name,
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

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityDescription(): ?string
    {
        return 'Document verwijderd: ' . $this->document_name;
    }
}
