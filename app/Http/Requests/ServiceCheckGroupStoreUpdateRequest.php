<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property array<int> $product_type_ids
 */
class ServiceCheckGroupStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:255'],
            'order'             => ['nullable', 'integer', 'min:0'],
            'product_type_ids'  => ['required', 'array', 'min:1'],
            'product_type_ids.*' => ['integer', 'exists:product_types,id'],
        ];
    }
}
