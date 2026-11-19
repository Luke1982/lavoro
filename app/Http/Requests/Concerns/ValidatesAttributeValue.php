<?php

namespace App\Http\Requests\Concerns;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The rules for one value of a kenmerk: free text that is unique within the
 * kenmerk, or for a numeric kenmerk a number that is.
 */
trait ValidatesAttributeValue
{
    protected function attributeValueRules(ProductAttribute $attribute, ?int $ignore_id = null): array
    {
        $rules = ['required', 'string', 'max:255'];

        if (!$attribute->isNumeric()) {
            $rules[] = Rule::unique('product_attribute_values', 'value')
                ->where('product_attribute_id', $attribute->id)
                ->ignore($ignore_id);

            return $rules;
        }

        $rules[] = function (string $field, mixed $value, Closure $fail) use ($attribute, $ignore_id) {
            $fields = $attribute->valueFields((string) $value);

            if ($fields === null) {
                $fail('Vul een getal in, zoals 1,5' . ($attribute->unit ? ' of 1,5 ' . $attribute->unit : '')
                    . ', met hoogstens zes cijfers achter de komma.');

                return;
            }

            $taken = ProductAttributeValue::where('product_attribute_id', $attribute->id)
                ->where('number', $fields['number'])
                ->when($ignore_id, fn ($query) => $query->whereKeyNot($ignore_id))
                ->exists();

            if ($taken) {
                $fail($fields['value'] . ' staat al in de lijst.');
            }
        };

        return $rules;
    }
}
