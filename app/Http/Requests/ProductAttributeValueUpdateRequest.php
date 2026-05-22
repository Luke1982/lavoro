<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductAttributeValueUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productattributevalue')->productAttribute);
    }

    public function rules(): array
    {
        $pav = $this->route('productattributevalue');

        return [
            'value' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_attribute_values', 'value')
                    ->where('product_attribute_id', $pav->product_attribute_id)
                    ->ignore($pav->id),
            ],
        ];
    }
}
