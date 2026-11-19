<?php

namespace App\Domain\Signals\Quotes;

use App\Domain\Signals\ChangeSentence;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Support\Quantity;

/**
 * The quantity of a product changed and the materials under it that nobody had
 * adjusted by hand changed with it: three units instead of two, three brackets
 * instead of two. The line is the product they followed.
 */
class StandardMaterialsFollowedProduct extends QuoteLineSignal
{
    /** @param array<int, array{line: QuoteLine, before: float, after: float}> $followed */
    public function __construct(Quote $quote, QuoteLine $line, public array $followed)
    {
        parent::__construct($quote, $line);
    }

    public static function key(): string
    {
        return 'quote.standard_materials_followed';
    }

    public static function label(): string
    {
        return 'Materialen aangepast aan aantal product';
    }

    public function activityCategory(): string
    {
        return 'material';
    }

    public function activityDescription(): ?string
    {
        return 'Mee met het aantal van ' . $this->line->description . ': ' . ChangeSentence::of($this->changes());
    }

    public function changes(): array
    {
        return array_map(fn (array $row) => [
            'field' => 'quantity',
            'label' => 'Aantal (' . $row['line']->description . ')',
            'old_value' => $row['before'],
            'new_value' => $row['after'],
            'old_label' => Quantity::human($row['before']),
            'new_label' => Quantity::human($row['after']),
        ], $this->followed);
    }
}
