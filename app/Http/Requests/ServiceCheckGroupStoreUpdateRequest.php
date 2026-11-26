<?php

namespace App\Http\Requests;

use App\Models\ServiceCheckGroup;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property array<int> $product_type_ids
 */
class ServiceCheckGroupStoreUpdateRequest extends FormRequest
{
    /** Making one asks for create, changing the one in the route for update. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->route('servicecheckgroup') ? 'update' : 'create', ServiceCheckGroup::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
            'product_type_ids' => ['required', 'array', 'min:1'],
            'product_type_ids.*' => ['integer', 'exists:product_types,id'],
        ];
    }
}
