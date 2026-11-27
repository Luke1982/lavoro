<?php

namespace App\Http\Requests;

use App\Enums\DefaultMaterialPrompts;
use App\Models\Material;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductMaterialDefaultStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageDefaults', Quote::class);
    }

    public function rules(): array
    {
        return [
            'material_id' => [
                'required',
                'integer',
                'exists:materials,id',
                Rule::unique('productables', 'productable_id')
                    ->where('product_id', $this->route('product')->id)
                    ->where('productable_type', Material::class),
            ],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'prompt' => ['required', Rule::enum(DefaultMaterialPrompts::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'material_id.unique' => 'Dit materiaal staat al bij dit product.',
        ];
    }
}
