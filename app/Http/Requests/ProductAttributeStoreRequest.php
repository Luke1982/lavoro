<?php

namespace App\Http\Requests;

use App\Enums\ProductAttributeValueTypes;
use App\Models\ProductAttribute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductAttributeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ProductAttribute::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:product_attributes,name'],
            'value_type' => ['sometimes', 'string', Rule::in(array_column(ProductAttributeValueTypes::cases(), 'name'))],
            'unit' => ['nullable', 'string', 'max:20', 'regex:/^\D/u'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (blank($this->input('value_type'))) {
            $this->merge(['value_type' => ProductAttributeValueTypes::text->name]);
        }
    }

    public function messages(): array
    {
        return [
            'unit.regex' => 'Een eenheid begint niet met een cijfer.',
        ];
    }
}
