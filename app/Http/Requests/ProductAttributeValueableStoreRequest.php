<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductAttributeValueableStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('productattribute.update');
    }

    public function rules(): array
    {
        $product_attribute_id = $this->input('product_attribute_id');

        return [
            'product_id'               => ['required', 'integer', Rule::exists('products', 'id')],
            'product_attribute_id'     => ['required', 'integer', Rule::exists('product_attributes', 'id')],
            'product_attribute_value_id' => [
                'nullable',
                'integer',
                Rule::exists('product_attribute_values', 'id')
                    ->where('product_attribute_id', $product_attribute_id),
            ],
        ];
    }
}
