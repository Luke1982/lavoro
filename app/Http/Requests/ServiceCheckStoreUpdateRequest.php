<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use App\Enums\ServiceCheckTypes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int    $product_type_id
 */
class ServiceCheckStoreUpdateRequest extends FormRequest
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
            'name'            => 'required|string|max:255',
            'product_type_id' => 'required|exists:product_types,id',
            'type'            => ['required', Rule::in(array_column(ServiceCheckTypes::cases(), 'name'))],
            'order'           => 'nullable|integer|min:0',
        ];
    }
}
