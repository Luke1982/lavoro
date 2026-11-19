<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\BaseSignal;
use App\Enums\QuoteLineKinds;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;

/**
 * A fact about a line of a quote. A line has no page of its own: its history is
 * read on the quote, so the quote is the subject and the line travels in the
 * wording, as "2 x Daikin Perfera 3,5 kW à €1.250,00".
 */
abstract class QuoteLineSignal extends BaseSignal implements QuoteSignal
{
    public function __construct(public Quote $quote, public QuoteLine $line)
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

    public function requiredPermission(): ?string
    {
        return 'quote.read';
    }

    /** A text line has no amount, so only its words are given. */
    protected static function summary(QuoteLine $line): string
    {
        if ($line->kind === QuoteLineKinds::text->value) {
            return (string) $line->description;
        }

        return Quantity::human($line->quantity) . ' x ' . $line->description . " \u{E0} " . Money::euros($line->unit_price)
            . ($line->is_optional ? ' (optioneel)' : '');
    }

    /** @param array<int, QuoteLine> $lines */
    protected static function listOf(array $lines): string
    {
        return implode(', ', array_map(fn (QuoteLine $line) => Quantity::human($line->quantity) . ' x ' . $line->description, $lines));
    }

    /**
     * A line coming on (before null) or going off (after null), as one change row.
     *
     * @return array<string, mixed>
     */
    protected static function lineRow(QuoteLine $line, bool $added): array
    {
        $summary = self::summary($line);

        return [
            'field' => 'line',
            'label' => 'Regel ' . $line->id,
            'old_value' => $added ? null : $summary,
            'new_value' => $added ? $summary : null,
            'old_label' => $added ? null : $summary,
            'new_label' => $added ? $summary : null,
        ];
    }
}
