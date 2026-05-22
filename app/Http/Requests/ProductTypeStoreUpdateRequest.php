<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Class ProductTypeStoreUpdateRequest
 *
 * Handles validation for storing and updating product types.
 * @property string $name
 * @property int|null $typical_certificate_days
 */
class ProductTypeStoreUpdateRequest extends FormRequest
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
        $ignore_id = $this->route('producttype')?->id;

        return [
            'name'                     => ['required', 'string', 'max:255', Rule::unique('product_types', 'name')->ignore($ignore_id)],
            'typical_certificate_days' => 'nullable|integer|min:1',
            'parent_id'                => 'nullable|integer|exists:product_types,id',
        ];
    }
}
