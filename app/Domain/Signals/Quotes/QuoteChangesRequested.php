<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;

/**
 * The customer asked for something to be different, in their own words. The
 * quote moves to "wijziging gevraagd", where it can be changed and sent again.
 */
class QuoteChangesRequested extends QuoteStatusChanged
{
    public function __construct(
        Quote $quote,
        string $previous_status,
        string $new_status,
        public string $requested_by,
        public string $message,
    ) {
        parent::__construct($quote, $previous_status, $new_status);
    }

    public static function key(): string
    {
        return 'quote.changes_requested';
    }

    public static function label(): string
    {
        return 'Wijziging gevraagd';
    }

    public function activityDescription(): ?string
    {
        return $this->requested_by . ' vroeg een wijziging van offerte ' . $this->quote->label()
            . ': ' . $this->message;
    }

    public function activityMetadata(): ?array
    {
        return ['requested_by' => $this->requested_by, 'message' => $this->message];
    }
}
