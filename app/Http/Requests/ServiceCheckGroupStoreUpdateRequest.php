<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int $product_type_id
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
            'name'            => ['required', 'string', 'max:255'],
            'order'           => ['nullable', 'integer', 'min:0'],
            'product_type_id' => ['required', 'exists:product_types,id'],
        ];
    }
}
