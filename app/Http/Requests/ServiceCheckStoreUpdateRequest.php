<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use App\Enums\ServiceCheckTypes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property array<int> $product_type_ids
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
            'name'                   => 'required|string|max:255',
            'product_type_ids'       => ['required', 'array', 'min:1'],
            'product_type_ids.*'     => ['integer', 'exists:product_types,id'],
            'service_check_group_id' => 'nullable|exists:service_check_groups,id',
            'type'                   => ['required', Rule::in(array_column(ServiceCheckTypes::cases(), 'name'))],
            'order'                  => 'nullable|integer|min:0',
        ];
    }
}
