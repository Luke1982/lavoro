<?php

namespace App\Http\Requests;

use App\Models\ProductAttribute;
use Illuminate\Foundation\Http\FormRequest;

class ProductAttributeReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attribute = $this->route('productattribute');

        return $attribute instanceof ProductAttribute
            ? $this->user()->can('view', $attribute)
            : $this->user()->can('viewAny', ProductAttribute::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
