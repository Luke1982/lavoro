<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class QuoteUnitStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', Quote::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:30', 'unique:quote_units,name'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Deze eenheid bestaat al.'];
    }
}
