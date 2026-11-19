<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAttributeValue;
use Illuminate\Foundation\Http\FormRequest;

class ProductAttributeValueStoreRequest extends FormRequest
{
    use ValidatesAttributeValue;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productattribute'));
    }

    public function rules(): array
    {
        return [
            'value' => $this->attributeValueRules($this->route('productattribute')),
        ];
    }
}
