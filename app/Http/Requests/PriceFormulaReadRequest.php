<?php

namespace App\Http\Requests;

use App\Models\PriceFormula;
use Illuminate\Foundation\Http\FormRequest;

class PriceFormulaReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $formula = $this->route('priceformula');

        return $formula
            ? $this->user()->can('view', $formula)
            : $this->user()->can('viewAny', PriceFormula::class);
    }

    public function rules(): array
    {
        return [
            'contract' => ['nullable', 'integer'],
        ];
    }
}
