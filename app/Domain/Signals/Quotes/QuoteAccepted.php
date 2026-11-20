<?php

namespace App\Domain\Signals\Quotes;

use App\Enums\QuoteAcceptedVia;
use App\Models\Quote;

/**
 * The customer said yes: with their name and signature, through the link or on
 * a screen in the app, and with the options they chose. The status it moves to
 * is part of this fact, so the trail tells it once.
 */
class QuoteAccepted extends QuoteStatusChanged
{
    /** @param array<int, string> $chosen_options descriptions of the optional lines taken */
    public function __construct(
        Quote $quote,
        string $previous_status,
        string $new_status,
        public string $accepted_by,
        public QuoteAcceptedVia $accepted_via,
        public array $chosen_options = [],
        public int $version = 1,
    ) {
        parent::__construct($quote, $previous_status, $new_status);
    }

    public static function key(): string
    {
        return 'quote.accepted';
    }

    public static function label(): string
    {
        return 'Offerte geaccepteerd';
    }

    public function activityDescription(): ?string
    {
        $sentence = $this->accepted_by . ' ging akkoord met versie ' . $this->version . ' van offerte ' . $this->quote->label()
            . ' ' . $this->accepted_via->phrase();

        return $this->chosen_options === [] ? $sentence : $sentence . ', met ' . implode(', ', $this->chosen_options);
    }

    public function activityMetadata(): ?array
    {
        return [
            'total' => $this->quote->totals()->describe(),
            'accepted_by' => $this->accepted_by,
            'via' => $this->accepted_via->value,
        ];
    }
}
