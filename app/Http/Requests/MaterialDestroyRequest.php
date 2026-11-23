<?php

namespace App\Http\Requests;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

class MaterialDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', Material::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
