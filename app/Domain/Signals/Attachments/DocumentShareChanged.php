<?php

namespace App\Domain\Signals\Attachments;

use App\Domain\Signals\BaseSignal;
use App\Models\Document;
use App\Models\Share;
use Illuminate\Database\Eloquent\Model;

/**
 * A document was shared from the record it is on, or no longer is: from a
 * quote with the customer, who gets it with the mail that sends the quote. The
 * document itself stays where it is either way.
 */
class DocumentShareChanged extends BaseSignal
{
    public function __construct(
        public Model $source,
        public Document $document,
        public bool $shared,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'document.share_changed';
    }

    public static function label(): string
    {
        return 'Delen van document gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'document';
    }

    public function subject(): Model
    {
        return $this->source;
    }

    public function activityDescription(): ?string
    {
        return 'Document "' . ($this->document->title ?: $this->document->name) . '" '
            . ($this->shared ? 'gedeeld' : 'niet meer gedeeld')
            . ' met ' . Share::audience($this->source);
    }

    public function changes(): array
    {
        return [[
            'field' => 'shared',
            'label' => 'Gedeeld met ' . Share::audience($this->source),
            'old_value' => $this->shared ? '0' : '1',
            'new_value' => $this->shared ? '1' : '0',
            'old_label' => $this->shared ? 'Nee' : 'Ja',
            'new_label' => $this->shared ? 'Ja' : 'Nee',
        ]];
    }
}
