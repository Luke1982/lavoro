<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;

/**
 * The quote was declined: by the customer on their page, with their name, or
 * marked so by a colleague. Either way with the reason when one was given, so
 * the lost quotes can be counted by reason.
 */
class QuoteDeclined extends QuoteStatusChanged
{
    public function __construct(
        Quote $quote,
        string $previous_status,
        string $new_status,
        public ?string $declined_by = null,
        public ?string $lost_reason = null,
        public ?string $note = null,
    ) {
        parent::__construct($quote, $previous_status, $new_status);
    }

    public static function key(): string
    {
        return 'quote.declined';
    }

    public static function label(): string
    {
        return 'Offerte afgewezen';
    }

    public static function coveredFields(): array
    {
        return [...parent::coveredFields(), 'lost_reason_id', 'lost_reason_note'];
    }

    public function activityDescription(): ?string
    {
        $sentence = $this->declined_by
            ? $this->declined_by . ' wees offerte ' . $this->quote->label() . ' af'
            : 'Offerte ' . $this->quote->label() . ' afgewezen';

        return $sentence
            . ($this->lost_reason ? ': ' . mb_strtolower($this->lost_reason) : '')
            . ($this->note ? '. ' . $this->note : '');
    }

    public function activityMetadata(): ?array
    {
        return [...parent::activityMetadata(), 'lost_reason' => $this->lost_reason, 'note' => $this->note];
    }
}
