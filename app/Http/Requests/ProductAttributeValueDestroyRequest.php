<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductAttributeValueDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productattributevalue')->productAttribute);
    }

    public function rules(): array
    {
        return [];
    }
}
