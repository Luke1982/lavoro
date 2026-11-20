<?php

namespace App\Domain\Signals\Quotes;

/**
 * A quote that is out with the customer runs out soon and nobody answered yet:
 * whoever made it is told, while there is still time to call.
 */
class QuoteExpiresSoon extends QuoteFact
{
    public static function key(): string
    {
        return 'quote.expires_soon';
    }

    public static function label(): string
    {
        return 'Offerte verloopt binnenkort';
    }

    public static function coveredFields(): array
    {
        return ['expiry_noticed_for'];
    }

    public function activityDescription(): ?string
    {
        return 'Offerte ' . $this->quote->label() . ' is geldig tot en met '
            . $this->quote->valid_until->format('d-m-Y') . ' en de klant heeft nog niet gereageerd';
    }
}
