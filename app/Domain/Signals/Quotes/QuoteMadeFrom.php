<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;
use App\Models\QuoteTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * A quote was started from something that already existed: a werkbon or a
 * storing where a technician found more work, another quote, or a template.
 * The trail says it on the quote, and on the werkbon or storing as well, so
 * both lead to the other.
 */
class QuoteMadeFrom extends QuoteFact
{
    public function __construct(Quote $quote, public Model $origin, public string $origin_label)
    {
        parent::__construct($quote);
    }

    public static function key(): string
    {
        return 'quote.made_from';
    }

    public static function label(): string
    {
        return 'Offerte gemaakt vanuit';
    }

    public static function coveredFields(): array
    {
        return ['origin_type', 'origin_id'];
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function activityDescription(): ?string
    {
        return 'Offerte ' . $this->quote->label() . ' gemaakt vanuit ' . $this->origin_label;
    }

    /** A template keeps no trail of its own. */
    public function activityContext(): array
    {
        return $this->origin instanceof QuoteTemplate ? [] : [$this->origin];
    }
}
