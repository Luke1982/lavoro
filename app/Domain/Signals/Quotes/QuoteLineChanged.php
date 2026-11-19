<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\ChangeSentence;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Support\Money;
use App\Support\Quantity;

/**
 * A line on a quote changed: its quantity, price, discount, VAT or text. Every
 * changed field is reported with its value before and after, so "what was the
 * price of the unit before it was lowered" has an answer.
 */
class QuoteLineChanged extends QuoteLineSignal
{
    private const LABELS = [
        'description' => 'Omschrijving',
        'quantity' => 'Aantal',
        'unit' => 'Eenheid',
        'unit_price' => 'Prijs per stuk',
        'cost_price' => 'Inkoopprijs',
        'vat_percent' => 'Btw',
        'discount_percent' => 'Korting',
        'is_optional' => 'Optioneel',
    ];

    /** @param array<string, array{0: mixed, 1: mixed}> $fields field => [before, after] */
    public function __construct(Quote $quote, QuoteLine $line, public array $fields)
    {
        parent::__construct($quote, $line);
    }

    public static function key(): string
    {
        return 'quote.line_changed';
    }

    public static function label(): string
    {
        return 'Offerteregel gewijzigd';
    }

    public function activityCategory(): string
    {
        return 'update';
    }

    /** A changed cost price is a margin, and only who may see margins reads it. */
    public function requiredPermission(): ?string
    {
        return array_key_exists('cost_price', $this->fields) ? 'quote.see_margins' : 'quote.read';
    }

    public function activityDescription(): ?string
    {
        return 'Offerte ' . $this->quote->number . ': ' . ChangeSentence::of($this->changes());
    }

    public function changes(): array
    {
        $changes = [];

        foreach ($this->fields as $field => [$before, $after]) {
            $changes[] = [
                'field' => $field,
                'label' => (self::LABELS[$field] ?? $field) . ' (' . $this->line->description . ')',
                'old_value' => $before,
                'new_value' => $after,
                'old_label' => self::readable($field, $before),
                'new_label' => self::readable($field, $after),
            ];
        }

        return $changes;
    }

    private static function readable(string $field, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $field === 'is_optional' => $value ? 'Ja' : 'Nee',
            in_array($field, ['unit_price', 'cost_price'], true) => Money::euros($value),
            in_array($field, ['vat_percent', 'discount_percent'], true) => Quantity::human($value) . '%',
            $field === 'quantity' => Quantity::human($value),
            default => (string) $value,
        };
    }
}
