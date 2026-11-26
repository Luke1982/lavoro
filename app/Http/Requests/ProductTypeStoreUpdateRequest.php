<?php

namespace App\Http\Requests;

use App\Models\ProductType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Class ProductTypeStoreUpdateRequest
 *
 * Handles validation for storing and updating product types.
 *
 * @property string $name
 * @property int|null $typical_certificate_days
 */
class ProductTypeStoreUpdateRequest extends FormRequest
{
    /** Changing a product type asks for producttype.update; ProductTypeStoreRequest asks for create. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', ProductType::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $ignore_id = $this->route('producttype')?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('product_types', 'name')->ignore($ignore_id)],
            'typical_certificate_days' => 'nullable|integer',
            'parent_id' => 'nullable|integer|exists:product_types,id',
        ];
    }
}
