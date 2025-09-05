<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:255',
            'description'   => 'nullable|string|max:1000',
            'material_category_id'   => 'nullable|exists:material_categories,id',
            'material_usage_unit_id' => 'nullable|exists:material_usage_units,id',
            'price'         => 'nullable|numeric|min:0',
            'snelstart_id'  => 'nullable|uuid',
            'code'          => 'nullable|string|max:255',
            'vendor_code'   => 'nullable|string|max:255',
            'cost_price'    => 'nullable|numeric|min:0',
            'divisable'     => 'boolean',
            'is_active'     => 'boolean',
            'is_service'    => 'boolean',
            'stock'         => 'required|numeric|min:0',
            'min_stock'     => 'required|numeric|min:0',
            'max_stock'     => 'required|numeric|min:0',
        ];
    }
}
