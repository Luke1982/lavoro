<?php

namespace App\Http\Requests;

use App\Models\ProductAttribute;
use Illuminate\Foundation\Http\FormRequest;

class ProductAttributeReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('read', ProductAttribute::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
