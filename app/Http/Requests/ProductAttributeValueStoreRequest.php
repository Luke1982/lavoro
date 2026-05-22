<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductAttributeValueStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productattribute'));
    }

    public function rules(): array
    {
        $attribute_id = $this->route('productattribute')->id;

        return [
            'value' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_attribute_values', 'value')->where('product_attribute_id', $attribute_id),
            ],
        ];
    }
}
