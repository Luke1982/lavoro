<?php

namespace App\Domain\Signals\Quotes;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLine;

/**
 * A product was offered, with the materials the person chose to offer along with
 * it. The materials that always come along are not in here yet: adding those is
 * what a listener does when it hears this.
 */
class QuoteProductAdded extends QuoteLineSignal
{
    /** @param array<int, QuoteLine> $chosen_materials */
    public function __construct(
        Quote $quote,
        QuoteLine $line,
        public Product $product,
        public array $chosen_materials = [],
    ) {
        parent::__construct($quote, $line);
    }

    public static function key(): string
    {
        return 'quote.product_added';
    }

    public static function label(): string
    {
        return 'Product geoffreerd';
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function activityDescription(): ?string
    {
        $sentence = 'Product toegevoegd aan offerte ' . $this->quote->number . ': ' . self::summary($this->line);

        return $this->chosen_materials === []
            ? $sentence
            : $sentence . ', met ' . self::listOf($this->chosen_materials);
    }

    public function changes(): array
    {
        return array_map(
            fn (QuoteLine $line) => self::lineRow($line, added: true),
            [$this->line, ...$this->chosen_materials],
        );
    }
}
