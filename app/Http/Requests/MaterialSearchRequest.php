<?php

namespace App\Http\Requests;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

class MaterialSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Material::class);
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:255'];
    }
}
