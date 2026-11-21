<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialSupplierStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('material.update') ?? false;
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
