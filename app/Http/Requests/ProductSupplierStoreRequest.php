<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductSupplierStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('product.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'article_number' => ['nullable', 'string', 'max:255'],
            'is_preferred' => ['sometimes', 'boolean'],
        ];
    }
}
