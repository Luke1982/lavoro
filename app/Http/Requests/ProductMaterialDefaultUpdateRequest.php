<?php

namespace App\Http\Requests;

use App\Enums\DefaultMaterialPrompts;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductMaterialDefaultUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageDefaults', Quote::class);
    }

    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999'],
            'prompt' => ['sometimes', 'required', Rule::enum(DefaultMaterialPrompts::class)],
        ];
    }
}
