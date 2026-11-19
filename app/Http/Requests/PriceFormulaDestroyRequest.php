<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PriceFormulaDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('priceformula'));
    }

    public function rules(): array
    {
        return [];
    }
}
