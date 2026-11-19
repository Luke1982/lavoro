<?php

namespace App\Http\Requests;

use App\Models\PriceFormula;
use Illuminate\Foundation\Http\FormRequest;

class PriceFormulaStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PriceFormula::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:price_formulas,name'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
