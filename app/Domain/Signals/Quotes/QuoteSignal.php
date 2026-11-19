<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\Signal;
use App\Models\Quote;

/**
 * Something happened to a quote. Listeners type-hint this to react to every
 * quote fact, or a concrete class to react to one.
 */
interface QuoteSignal extends Signal
{
    public function quote(): Quote;
}
