<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Quotes\QuoteLineDrafts;
use App\Domain\Quotes\VatRates;
use App\Domain\Signals\Quotes\QuoteLineAdded;
use App\Domain\Signals\Signals;
use App\Enums\QuoteLineKinds;
use App\Models\Material;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Arr;

/**
 * Puts a material, a line of its own (hours, a delivery, anything not in the
 * catalogue) or a text on a quote. A product goes through AddProductToQuoteAction,
 * because it brings its materials along.
 */
class AddQuoteLineAction
{
    private const OWN_LINE_FIELDS = ['description', 'quantity', 'unit', 'unit_price', 'cost_price', 'vat_percent'];

    /** @param array<string, mixed> $data the validated fields */
    public function execute(Quote $quote, QuoteLineKinds $kind, array $data): QuoteLine
    {
        QuoteGuard::editable($quote);

        $line = $quote->addLine(match ($kind) {
            QuoteLineKinds::material => QuoteLineDrafts::forMaterial(
                Material::findOrFail($data['material_id']),
                (float) ($data['quantity'] ?? 1),
            ),
            QuoteLineKinds::free => [
                'kind' => $kind->value,
                'quantity' => 1,
                'vat_percent' => VatRates::DEFAULT,
                ...array_filter(Arr::only($data, self::OWN_LINE_FIELDS), fn ($value) => $value !== null),
            ],
            QuoteLineKinds::text => ['kind' => $kind->value, 'description' => $data['description'], 'quantity' => 0],
            QuoteLineKinds::product => throw new \LogicException('A product is offered through AddProductToQuoteAction.'),
        });

        Signals::dispatch(new QuoteLineAdded($quote, $line));

        return $line;
    }
}
