<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;
use App\Models\QuoteVersion;

/**
 * The customer opened the link to a version for the first time: the moment to
 * call them, while it is fresh. Told once per send.
 */
class QuoteViewed extends QuoteFact
{
    public function __construct(Quote $quote, public QuoteVersion $version)
    {
        parent::__construct($quote);
    }

    public static function key(): string
    {
        return 'quote.viewed';
    }

    public static function label(): string
    {
        return 'Offerte bekeken';
    }

    public function activityCategory(): string
    {
        return 'other';
    }

    public function activityDescription(): ?string
    {
        return 'De klant bekeek versie ' . $this->version->number . ' van offerte ' . $this->quote->label()
            . ($this->version->recipient ? ', verstuurd aan ' . $this->version->recipient : '');
    }
}
