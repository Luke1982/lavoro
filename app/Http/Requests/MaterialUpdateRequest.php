<?php

namespace App\Http\Requests;

use App\Models\Material;
use App\Rules\DbRange;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'material_category_id' => 'nullable|exists:material_categories,id',
            'material_usage_unit_id' => 'nullable|exists:material_usage_units,id',
            'price' => ['nullable', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'snelstart_id' => 'nullable|uuid',
            'code' => 'nullable|string|max:255',
            'vendor_code' => 'nullable|string|max:255',
            'divisable' => 'boolean',
            'is_active' => 'boolean',
            'is_service' => 'boolean',
            'stock' => ['required', 'numeric', DbRange::decimal(10, 2)],
            'min_stock' => ['required', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'max_stock' => ['required', 'numeric', 'min:0', DbRange::decimal(10, 2)],
        ], $this->financialRules());
    }

    /**
     * Only from whoever may see them: anyone else's form loaded them empty, and
     * saving it back would wipe the real values.
     *
     * @return array<string, array<int, mixed>>
     */
    private function financialRules(): array
    {
        if (!$this->user()->can('seeFinancials', Material::class)) {
            return [];
        }

        return [
            'cost_price' => ['nullable', 'numeric', 'min:0', DbRange::decimal(10, 2)],
        ];
    }
}
