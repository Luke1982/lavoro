<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;
use App\Models\QuoteLine;

/**
 * A line came off a quote, and with a product the materials that hung under it.
 * The lines are already gone when this is heard; what they were is carried here.
 */
class QuoteLineRemoved extends QuoteLineSignal
{
    /** @param array<int, QuoteLine> $children */
    public function __construct(Quote $quote, QuoteLine $line, public array $children = [])
    {
        parent::__construct($quote, $line);
    }

    public static function key(): string
    {
        return 'quote.line_removed';
    }

    public static function label(): string
    {
        return 'Offerteregel verwijderd';
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        $sentence = 'Regel verwijderd van offerte ' . $this->quote->number . ': ' . self::summary($this->line);

        return $this->children === [] ? $sentence : $sentence . ', met ' . self::listOf($this->children);
    }

    public function changes(): array
    {
        return array_map(fn (QuoteLine $line) => self::lineRow($line, added: false), [$this->line, ...$this->children]);
    }
}
