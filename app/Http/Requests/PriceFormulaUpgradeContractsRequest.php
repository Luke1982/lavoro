<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PriceFormulaUpgradeContractsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('upgradeContracts', $this->route('priceformula'));
    }

    public function rules(): array
    {
        return [
            'contract_ids' => ['nullable', 'array'],
            'contract_ids.*' => ['integer'],
        ];
    }
}
