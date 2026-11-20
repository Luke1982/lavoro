<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;
use App\Models\QuoteLine;

/**
 * A line became an alternative to the line above it, so the customer takes one
 * of the two, or stopped being one and counts by itself again.
 */
class QuoteAlternativeChanged extends QuoteFact
{
    public function __construct(Quote $quote, public QuoteLine $line, public ?QuoteLine $alternative_to)
    {
        parent::__construct($quote);
    }

    public static function key(): string
    {
        return 'quote.alternative_changed';
    }

    public static function label(): string
    {
        return 'Alternatief aangepast';
    }

    public function activityDescription(): ?string
    {
        return $this->alternative_to
            ? $this->line->description . ' is op offerte ' . $this->quote->number . ' een alternatief voor '
                . $this->alternative_to->description
            : $this->line->description . ' is op offerte ' . $this->quote->number . ' geen alternatief meer';
    }
}
