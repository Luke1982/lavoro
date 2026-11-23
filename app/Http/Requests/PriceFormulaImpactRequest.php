<?php

namespace App\Http\Requests;

use App\Rules\PriceFormulaGraph;
use Illuminate\Foundation\Http\FormRequest;

/** Asked by the editor before saving: what saving this graph would do to the contracts on the formula. */
class PriceFormulaImpactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('priceformula'));
    }

    public function rules(): array
    {
        return [
            'graph' => ['required', 'array', new PriceFormulaGraph],
        ];
    }
}
