<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;

/**
 * Whoever makes the quote decided that what follows is a new version: what the
 * customer was sent before stays as it was, under the old number, and the next
 * send goes out under this one.
 */
class QuoteVersionStarted extends QuoteFact
{
    public function __construct(Quote $quote, public int $previous)
    {
        parent::__construct($quote);
    }

    public static function key(): string
    {
        return 'quote.version_started';
    }

    public static function label(): string
    {
        return 'Nieuwe versie';
    }

    public static function coveredFields(): array
    {
        return ['version'];
    }

    public function activityDescription(): ?string
    {
        return 'Versie ' . $this->quote->version . ' van offerte ' . $this->quote->label() . ' gestart';
    }

    public function changes(): array
    {
        return [[
            'field' => 'version',
            'label' => 'Versie',
            'old_value' => (string) $this->previous,
            'new_value' => (string) $this->quote->version,
            'old_label' => (string) $this->previous,
            'new_label' => (string) $this->quote->version,
        ]];
    }
}
