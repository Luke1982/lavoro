<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductSupplierDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('product.update') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
