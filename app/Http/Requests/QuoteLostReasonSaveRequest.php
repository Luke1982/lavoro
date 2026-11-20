<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reason a quote can be lost for, and whether the customer may pick it when
 * they decline: a new one, or a change to one.
 */
class QuoteLostReasonSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', Quote::class);
    }

    public function rules(): array
    {
        return [
            'name' => [
                ...($this->route('reason') ? ['sometimes'] : []), 'required', 'string', 'max:100',
                Rule::unique('quote_lost_reasons', 'name')->ignore($this->route('reason')),
            ],
            'customer_can_pick' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Deze reden bestaat al.'];
    }
}
