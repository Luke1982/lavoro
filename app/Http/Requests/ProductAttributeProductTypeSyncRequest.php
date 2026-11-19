<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductAttributeProductTypeSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productattribute'));
    }

    public function rules(): array
    {
        return [
            'product_type_ids' => ['present', 'array'],
            'product_type_ids.*' => ['integer', 'exists:product_types,id'],
        ];
    }
}
