<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Quote;
use App\Models\QuoteLine;

/**
 * The materials set to always come along with a product went onto the quote
 * under it, without anyone being asked. Its own fact, so the trail can tell
 * "Jan chose the bracket" from "the bracket came with the unit". The line is
 * the product they came with.
 */
class StandardMaterialsAdded extends QuoteLineSignal
{
    /** @param array<int, QuoteLine> $materials */
    public function __construct(Quote $quote, QuoteLine $line, public array $materials)
    {
        parent::__construct($quote, $line);
    }

    public static function key(): string
    {
        return 'quote.standard_materials_added';
    }

    public static function label(): string
    {
        return 'Standaardmaterialen meegenomen';
    }

    public function activityCategory(): string
    {
        return 'material';
    }

    public function activityDescription(): ?string
    {
        return 'Met ' . $this->line->description . ' kwam vanzelf mee: ' . self::listOf($this->materials);
    }

    public function changes(): array
    {
        return array_map(fn (QuoteLine $material) => self::lineRow($material, added: true), $this->materials);
    }
}
