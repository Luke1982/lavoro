<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;
use App\Models\QuoteVersion;

/**
 * A customer who had not answered was reminded, once, with a fresh link to the
 * same version. Sent by the app, so the trail says when and to whom.
 */
class QuoteReminderSent extends QuoteFact
{
    public function __construct(Quote $quote, public QuoteVersion $version)
    {
        parent::__construct($quote);
    }

    public static function key(): string
    {
        return 'quote.reminder_sent';
    }

    public static function label(): string
    {
        return 'Herinnering verstuurd';
    }

    public function activityDescription(): ?string
    {
        return 'Herinnering voor versie ' . $this->version->number . ' van offerte ' . $this->quote->label()
            . ' verstuurd aan ' . $this->version->recipient;
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->quote->customer]));
    }
}
