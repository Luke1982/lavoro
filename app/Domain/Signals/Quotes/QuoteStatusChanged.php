<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\BaseSignal;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Model;

/**
 * A quote moved to another status: sent, accepted, declined, lapsed, or back to
 * concept to be changed. The reason is set when the app moved it by itself, so
 * the trail says why a quote lapsed while nobody touched it.
 */
class QuoteStatusChanged extends BaseSignal implements QuoteSignal
{
    public function __construct(
        public Quote $quote,
        public string $previous_status,
        public string $new_status,
        public ?string $reason = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'quote.status_changed';
    }

    public static function label(): string
    {
        return 'Offertestatus gewijzigd';
    }

    public static function coveredFields(): array
    {
        return ['status', 'sent_at', 'decided_at'];
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
        return 'status';
    }

    public function requiredPermission(): ?string
    {
        return 'quote.read';
    }

    public function activityDescription(): ?string
    {
        $sentence = 'Status van offerte ' . $this->quote->label() . " gewijzigd van '"
            . $this->previous_status . "' naar '" . $this->new_status . "'";

        return $this->reason === null ? $sentence : $sentence . ', ' . $this->reason;
    }

    /** Whether a customer was offered something, and what they said, is news on the customer too. */
    public function activityContext(): array
    {
        return array_values(array_filter([$this->quote->customer, $this->quote->project]));
    }

    public function activityMetadata(): ?array
    {
        return ['total' => $this->quote->totals()->describe(), 'reason' => $this->reason];
    }

    public function changes(): array
    {
        return [[
            'field' => 'status',
            'label' => 'Status offerte ' . $this->quote->number,
            'old_value' => $this->previous_status,
            'new_value' => $this->new_status,
            'old_label' => $this->previous_status,
            'new_label' => $this->new_status,
        ]];
    }
}
