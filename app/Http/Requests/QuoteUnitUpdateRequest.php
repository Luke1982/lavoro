<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteUnitUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', Quote::class);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:30',
                Rule::unique('quote_units', 'name')->ignore($this->route('unit')),
            ],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Deze eenheid bestaat al.'];
    }
}
