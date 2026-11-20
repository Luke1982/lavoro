<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\BaseSignal;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Model;

/**
 * The lines of a quote, or the materials under one product, were put in another
 * order. What it reads like for the customer changes, so the trail says it; the
 * new order is the quote itself.
 */
class QuoteLinesReordered extends BaseSignal implements QuoteSignal
{
    /** @param array<int, string> $order the lines by description, in their new order */
    public function __construct(public Quote $quote, public array $order)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'quote.lines_reordered';
    }

    public static function label(): string
    {
        return 'Volgorde aangepast';
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
        return 'update';
    }

    public function requiredPermission(): ?string
    {
        return 'quote.read';
    }

    /** Dragging a line twice in one go is one change of order. */
    public function mergeKey(): ?string
    {
        return 'quote.lines_reordered.' . $this->quote->getKey();
    }

    public function activityDescription(): ?string
    {
        return 'Volgorde van de regels van offerte ' . $this->quote->number . ' aangepast: '
            . implode(', ', $this->order);
    }
}
