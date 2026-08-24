<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductableUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productable'));
    }

    /**
     * A flex part settles its aantal when the bundle is sold, so the catalogue number is
     * pinned rather than left at whatever stood in the field when the switch was flipped.
     */
    protected function prepareForValidation(): void
    {
        if ($this->boolean('flex_quantity')) {
            $this->merge(['quantity' => 1]);
        }
    }

    public function rules(): array
    {
        return [
            'product_relation_id' => ['nullable', 'integer', 'exists:product_relations,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'flex_quantity' => ['boolean'],
            'is_required' => ['boolean'],
        ];
    }
}
