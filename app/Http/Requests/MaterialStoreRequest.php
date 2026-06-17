<?php

namespace App\Http\Requests;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Material store authorization & validation.
 *
 * @method array validated()
 */
class MaterialStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Material::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'material_category_id' => ['required', 'exists:material_categories,id'],
            'code' => ['nullable', 'string', 'max:255'],
            'vendor_code' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'material_usage_unit_id' => ['required', 'exists:material_usage_units,id'],
            'divisable' => ['boolean'],
            'is_active' => ['boolean'],
            'is_service' => ['boolean'],
            'stock' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
