<?php

namespace App\Actions\Products;

use App\Domain\Signals\Products\ProductAttributeValueAdded;
use App\Domain\Signals\Signals;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;

/**
 * The value of a kenmerk that reads as the input, added to its list when it is
 * not there yet: the same number for a numeric kenmerk ("10" is "10 kW"), the
 * same text regardless of case otherwise.
 */
class FindOrAddProductAttributeValueAction
{
    /** @param  array{value: string, number: ?float}  $fields  from ProductAttribute::valueFields() */
    public function handle(ProductAttribute $attribute, array $fields): ProductAttributeValue
    {
        $existing = $attribute->values()
            ->when(
                $attribute->isNumeric(),
                fn ($query) => $query->where('number', $fields['number']),
                fn ($query) => $query->whereRaw('LOWER(value) = ?', [mb_strtolower($fields['value'])]),
            )
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $value = $attribute->values()->create($fields);
        Signals::dispatch(new ProductAttributeValueAdded($attribute, $value->value));

        return $value;
    }
}
