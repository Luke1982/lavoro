<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class QuoteUnitsOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', Quote::class);
    }

    public function rules(): array
    {
        return [
            'unit_ids' => ['required', 'array'],
            'unit_ids.*' => ['integer', 'distinct', 'exists:quote_units,id'],
        ];
    }
}
