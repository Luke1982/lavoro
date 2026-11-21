<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductSupplierUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('product.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'article_number' => ['nullable', 'string', 'max:255'],
            'is_preferred' => ['sometimes', 'boolean'],
        ];
    }
}
