<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\BaseSignal;
use App\Models\Quote;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * An accepted quote became a werkbon. On the quote, on the werkbon and on the
 * customer, because on each of them someone will ask where the other came from.
 * Only numbers are in it, so whoever reads the werkbon may read this too.
 */
class QuoteConvertedToServiceOrder extends BaseSignal implements QuoteSignal
{
    public function __construct(public Quote $quote, public ServiceOrder $service_order)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'quote.converted';
    }

    public static function label(): string
    {
        return 'Werkbon gemaakt van offerte';
    }

    public static function coveredFields(): array
    {
        return ['service_order_id'];
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
        return 'created';
    }

    public function activityDescription(): ?string
    {
        return 'Werkbon ' . $this->service_order->number . ' gemaakt van offerte ' . $this->quote->label();
    }

    public function activityContext(): array
    {
        return array_values(array_filter([$this->service_order, $this->quote->customer]));
    }
}
