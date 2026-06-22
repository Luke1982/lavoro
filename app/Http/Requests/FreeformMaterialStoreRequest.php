<?php

namespace App\Http\Requests;

use App\Models\FreeformMaterial;
use Illuminate\Foundation\Http\FormRequest;

class FreeformMaterialStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FreeformMaterial::class);
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:255'],
            'unforseen' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.numeric' => 'Voer een geldig aantal in.',
            'quantity.min' => 'Het aantal moet minimaal :min zijn.',
        ];
    }
}
