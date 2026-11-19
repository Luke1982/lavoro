<?php

namespace App\Http\Requests;

use App\Models\MaintenanceContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Putting a contract on a formula, moving it to the formula's newest version,
 * taking the formula off, or filling in the formula's inputs. All of it sets the
 * price, so all of it takes the right to see prices.
 */
class MaintenanceContractPriceFormulaUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('maintenancecontract'))
            && $this->user()->can('seeFinancials', MaintenanceContract::class);
    }

    public function rules(): array
    {
        return [
            'price_formula_id' => ['sometimes', 'nullable', 'integer', 'exists:price_formulas,id'],
            'to_latest' => ['sometimes', 'boolean'],
            'inputs' => ['sometimes', 'nullable', 'array'],
            'inputs.*' => ['nullable', 'numeric', 'between:-1000000000,1000000000'],
        ];
    }
}
