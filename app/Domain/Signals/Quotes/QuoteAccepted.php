<?php

namespace App\Domain\Signals\Quotes;

use App\Enums\QuoteAcceptedVia;
use App\Models\Quote;

/**
 * The customer said yes: with their name and signature, through the link or on
 * a screen in the app, and with the options and alternatives they chose. The
 * status it moves to is part of this fact, so the trail tells it once.
 */
class QuoteAccepted extends QuoteStatusChanged
{
    /**
     * @param  array<int, string>  $chosen_options  descriptions of the options and alternatives taken
     * @param  string|null  $recipient  where the link that was answered had gone
     */
    public function __construct(
        Quote $quote,
        string $previous_status,
        string $new_status,
        public string $accepted_by,
        public QuoteAcceptedVia $accepted_via,
        public array $chosen_options = [],
        public int $version = 1,
        public ?string $recipient = null,
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
        $sentence = $this->accepted_by . ' ging akkoord met versie ' . $this->version
            . ' van offerte ' . $this->quote->label()
            . ' ' . $this->accepted_via->phrase();

        return $sentence
            . ($this->chosen_options === [] ? '' : ', met ' . implode(', ', $this->chosen_options))
            . ($this->quote->accepted_terms_name ? ', en met de algemene voorwaarden' : '')
            . ($this->quote->starts_within_cooling_off ? '. De klant vroeg binnen de bedenktijd te beginnen' : '');
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
