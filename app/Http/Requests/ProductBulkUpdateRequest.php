<?php

namespace App\Http\Requests;

use App\Models\ProductAttributeValue;
use Illuminate\Foundation\Http\FormRequest;

class ProductBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('product.update');
    }

    public function rules(): array
    {
        return [
            'product_ids'                             => ['required', 'array', 'min:1'],
            'product_ids.*'                           => ['integer', 'exists:products,id'],
            'brand_id'                                => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'product_type_id'                         => [
                'sometimes',
                'nullable',
                'integer',
                'exists:product_types,id',
            ],
            'attributes'                              => ['sometimes', 'array'],
            'attributes.*.product_attribute_id'       => ['required', 'integer', 'exists:product_attributes,id'],
            'attributes.*.product_attribute_value_id' => [
                'required',
                'integer',
                'exists:product_attribute_values,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $index  = explode('.', $attribute)[1];
                    $attrId = $this->input("attributes.{$index}.product_attribute_id");
                    if (
                        ! ProductAttributeValue::where('id', $value)
                            ->where('product_attribute_id', $attrId)
                            ->exists()
                    ) {
                        $fail('De geselecteerde waarde hoort niet bij het opgegeven kenmerk.');
                    }
                },
            ],
        ];
    }
}
