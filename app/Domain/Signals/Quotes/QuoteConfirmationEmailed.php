<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;
use App\Models\QuoteVersion;

/**
 * The customer who said yes was sent their copy: the quote with their name and
 * signature, and the terms that came with it. Sent by the app, so the trail
 * says when and to whom.
 */
class QuoteConfirmationEmailed extends QuoteFact
{
    public function __construct(Quote $quote, public string $recipient, public ?QuoteVersion $version = null)
    {
        parent::__construct($quote);
    }

    public static function key(): string
    {
        return 'quote.confirmation_emailed';
    }

    public static function label(): string
    {
        return 'Bevestiging van akkoord verstuurd';
    }

    public function activityCategory(): string
    {
        return 'email';
    }

    public function activityDescription(): ?string
    {
        return 'Bevestiging van het akkoord op ' . ($this->version ? 'versie ' . $this->version->number . ' van ' : '')
            . 'offerte ' . $this->quote->label() . ', met de getekende offerte, verstuurd aan ' . $this->recipient;
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->quote->customer]));
    }

    public function activityMetadata(): ?array
    {
        return ['recipient' => $this->recipient, 'version' => $this->version?->number];
    }
}
