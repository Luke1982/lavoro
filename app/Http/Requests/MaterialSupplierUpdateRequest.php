<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialSupplierUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('material.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'article_number' => ['nullable', 'string', 'max:255'],
            'is_preferred' => ['sometimes', 'boolean'],
        ];
    }
}
