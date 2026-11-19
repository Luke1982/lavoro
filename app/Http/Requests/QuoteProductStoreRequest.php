<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A product offered, with the materials chosen in the question that came up.
 * Only standard materials of this product can be chosen.
 */
class QuoteProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'));
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'materials' => ['sometimes', 'array'],
            'materials.*.default_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('product_material_defaults', 'id')->where('product_id', $this->integer('product_id')),
            ],
            'materials.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
        ];
    }

    public function messages(): array
    {
        return [
            'materials.*.default_id.exists' => 'Dit materiaal hoort niet bij dit product.',
            'materials.*.quantity.gt' => 'Geef een aantal groter dan nul, of vink het materiaal uit.',
        ];
    }
}
