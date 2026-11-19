<?php

namespace App\Domain\Signals\Quotes;

/**
 * A line went onto a quote: a material, a line of its own, or a text. A product
 * is announced by QuoteProductAdded, because a product brings its materials.
 */
class QuoteLineAdded extends QuoteLineSignal
{
    public static function key(): string
    {
        return 'quote.line_added';
    }

    public static function label(): string
    {
        return 'Offerteregel toegevoegd';
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function activityDescription(): ?string
    {
        return 'Regel toegevoegd aan offerte ' . $this->quote->number . ': ' . self::summary($this->line);
    }

    public function changes(): array
    {
        return [self::lineRow($this->line, added: true)];
    }
}
