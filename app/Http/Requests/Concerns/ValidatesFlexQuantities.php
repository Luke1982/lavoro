<?php

namespace App\Http\Requests\Concerns;

use App\Models\Product;
use App\Models\Productable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * A bundle part whose aantal is flex is not settled by the product: one omvormer with
 * however many panelen this roof takes. The number is filled in on the werkbon taak.
 *
 * Creating a taak and changing one raise the same three questions — is this part of the
 * bundle, is it named twice, and does every verplicht part have a number — so both ask
 * them here.
 */
trait ValidatesFlexQuantities
{
    protected function validateFlexQuantities(Validator $validator, ?Product $product): void
    {
        $rows = $this->input('flex_parts', []);
        $flex_parts = $this->flexPartsOf($product);

        if ($flex_parts->isEmpty()) {
            if ($rows !== []) {
                $validator->errors()->add(
                    'flex_parts',
                    'Dit product heeft geen onderdelen met een vrij aantal.'
                );
            }

            return;
        }

        $quantities = [];

        foreach ($rows as $index => $row) {
            $product_id = (int) ($row['product_id'] ?? 0);

            if (!$flex_parts->has($product_id)) {
                $validator->errors()->add(
                    "flex_parts.{$index}.product_id",
                    'Dit onderdeel heeft geen vrij aantal binnen deze bundel.'
                );

                continue;
            }

            if (array_key_exists($product_id, $quantities)) {
                $validator->errors()->add(
                    "flex_parts.{$index}.product_id",
                    'Dit onderdeel staat er al in; verhoog het aantal in plaats van het opnieuw te kiezen.'
                );

                continue;
            }

            $quantities[$product_id] = (int) ($row['quantity'] ?? 0);
        }

        foreach ($flex_parts as $product_id => $part) {
            if (!$part->is_required || ($quantities[$product_id] ?? 0) >= 1) {
                continue;
            }

            $validator->errors()->add(
                'flex_parts',
                'Vul een aantal in voor ' . ($part->childProduct?->display_name ?? 'het verplichte onderdeel') . '.'
            );
        }
    }

    /**
     * The bundle's flex parts, keyed by the product they stand for — the key the taak
     * addresses them by, since a bundle lists a given product at most once.
     *
     * @return Collection<int, Productable>
     */
    protected function flexPartsOf(?Product $product): Collection
    {
        if (!$product?->bundle) {
            return collect();
        }

        return $product->productables
            ->where('flex_quantity', true)
            ->keyBy(fn (Productable $productable) => (int) $productable->productable_id);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function flexQuantityRules(): array
    {
        return [
            'flex_parts' => ['sometimes', 'array'],
            'flex_parts.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'flex_parts.*.quantity' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }
}
