<?php

namespace App\Http\Requests;

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
        ];
    }
}
