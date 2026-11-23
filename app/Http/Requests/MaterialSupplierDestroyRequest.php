<?php

namespace App\Http\Requests;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

class MaterialSupplierDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Material::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
