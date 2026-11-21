<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialSupplierDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('material.update') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
