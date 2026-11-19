<?php

namespace App\Http\Requests;

use App\Models\MaintenanceContract;
use Illuminate\Foundation\Http\FormRequest;

class PriceFormulaUpgradeContractsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('priceformula'))
            && $this->user()->can('update', new MaintenanceContract)
            && $this->user()->can('seeFinancials', MaintenanceContract::class);
    }

    public function rules(): array
    {
        return [
            'contract_ids' => ['nullable', 'array'],
            'contract_ids.*' => ['integer'],
        ];
    }
}
