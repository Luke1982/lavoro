<?php

namespace App\Http\Requests;

use App\Enums\ServiceCheckTypes;
use App\Models\ServiceCheck;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property array<int> $product_type_ids
 */
class ServiceCheckStoreUpdateRequest extends FormRequest
{
    /** Making one asks for create, changing the one in the route for update. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->route('servicecheck') ? 'update' : 'create', ServiceCheck::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'product_type_ids' => ['required', 'array', 'min:1'],
            'product_type_ids.*' => ['integer', 'exists:product_types,id'],
            'service_check_group_id' => 'nullable|exists:service_check_groups,id',
            'type' => ['required', Rule::in(array_column(ServiceCheckTypes::cases(), 'name'))],
            'order' => 'nullable|integer|min:0',
        ];
    }
}
