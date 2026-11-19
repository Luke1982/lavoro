<?php

namespace App\Http\Requests;

use App\Rules\PriceFormulaGraph;
use Illuminate\Foundation\Http\FormRequest;

class PriceFormulaGraphUpdateRequest extends FormRequest
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
