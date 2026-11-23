<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Naming a version of a price formula, such as after the year its prices are for. */
class PriceFormulaVersionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('priceformula'));
    }

    public function rules(): array
    {
        return [
            'version' => [
                'required', 'integer',
                Rule::exists('price_formula_versions', 'number')
                    ->where('price_formula_id', $this->route('priceformula')->id),
            ],
            'name' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'naam van de versie'];
    }
}
