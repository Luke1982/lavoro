<?php

namespace App\Http\Requests;

use App\Models\ProductAttribute;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
