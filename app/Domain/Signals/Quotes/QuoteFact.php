<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\BaseSignal;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Model;

/**
 * A fact about one quote that only the quote's readers may read: the ground
 * every quote signal without a status of its own stands on.
 */
abstract class QuoteFact extends BaseSignal implements QuoteSignal
{
    public function __construct(public Quote $quote)
    {
        parent::__construct();
    }

    public function quote(): Quote
    {
        return $this->quote;
    }

    public function subject(): Model
    {
        return $this->quote;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function requiredPermission(): ?string
    {
        return 'quote.read';
    }
}
