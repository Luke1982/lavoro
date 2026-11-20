<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Quotes\QuoteLineDrafts;
use App\Domain\Signals\Quotes\QuoteLineAdded;
use App\Domain\Signals\Signals;
use App\Enums\QuoteLineKinds;
use App\Models\Material;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Arr;

/**
 * Puts a material, a line of its own (hours, a delivery, anything not in the
 * catalogue) or a text on a quote: at the bottom, or, for a material or a line
 * of its own, under the product it belongs with. A product goes through AddProductToQuoteAction,
 * because it brings its materials along.
 */
class AddQuoteLineAction
{
    private const OWN_LINE_FIELDS = ['description', 'details', 'quantity', 'unit', 'unit_price', 'cost_price', 'vat_percent'];

    /** @param array<string, mixed> $data the validated fields */
    public function execute(Quote $quote, QuoteLineKinds $kind, array $data): QuoteLine
    {
        QuoteGuard::editable($quote);

        $attributes = [
            ...match ($kind) {
                QuoteLineKinds::material => QuoteLineDrafts::forMaterial(
                    $quote,
                    Material::findOrFail($data['material_id']),
                    (float) ($data['quantity'] ?? 1),
                ),
                QuoteLineKinds::free => [
                    'kind' => $kind->value,
                    'quantity' => 1,
                    'vat_percent' => $quote->default_vat_percent,
                    ...array_filter(Arr::only($data, self::OWN_LINE_FIELDS), fn ($value) => $value !== null),
                ],
                QuoteLineKinds::text => [
                    'kind' => $kind->value,
                    'description' => $data['description'],
                    'details' => $data['details'] ?? null,
                    'quantity' => 0,
                ],
                QuoteLineKinds::product => throw new \LogicException('A product is offered through AddProductToQuoteAction.'),
            },
            'is_optional' => (bool) ($data['is_optional'] ?? false),
        ];

        $line = isset($data['parent_id'])
            ? $quote->lines()->findOrFail($data['parent_id'])->addChild($attributes)
            : $quote->addLine($attributes);

        Signals::dispatch(new QuoteLineAdded($quote, $line));

        return $line;
    }
}
