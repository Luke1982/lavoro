<?php

namespace App\Http\Requests;

use App\Enums\ProductAttributeValueTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductAttributeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productattribute'));
    }

    public function rules(): array
    {
        $id = $this->route('productattribute')->id;

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('product_attributes', 'name')->ignore($id)],
            'searchable' => ['sometimes', 'boolean'],
            'value_type' => ['sometimes', 'string', Rule::in(array_column(ProductAttributeValueTypes::cases(), 'name'))],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\D/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'unit.regex' => 'Een eenheid begint niet met een cijfer.',
        ];
    }
}
