<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use App\Rules\PriceFormulaGraph;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a graph being edited comes to: over made-up machines, or over the
 * machines of a real contract. The second shows that contract's money, so it
 * takes the right to see contract prices as well.
 */
class PriceFormulaPreviewRequest extends FormRequest
{
    private const MAX_MACHINES = 1000;

    public function authorize(): bool
    {
        if (!$this->user()->can('viewAny', PriceFormula::class)) {
            return false;
        }

        $contract_id = $this->input('maintenance_contract_id');

        if (!$contract_id) {
            return true;
        }

        $contract = MaintenanceContract::find($contract_id);

        return $contract === null
            || ($this->user()->can('view', $contract)
                && $this->user()->can('seeFinancials', MaintenanceContract::class));
    }

    public function rules(): array
    {
        $intervals = 'in:' . ContractInterval::validationString();

        return [
            'graph' => ['required', 'array', new PriceFormulaGraph],
            'maintenance_contract_id' => ['nullable', 'integer', 'exists:maintenance_contracts,id'],
            'sample' => ['nullable', 'array', 'max:50', function (string $attribute, mixed $value, Closure $fail) {
                if (array_sum(array_map(fn ($line) => (int) ($line['quantity'] ?? 0), (array) $value)) > self::MAX_MACHINES) {
                    $fail('Een voorbeeldklant heeft hoogstens ' . self::MAX_MACHINES . ' machines.');
                }
            }],
            'sample.*.product_type_id' => ['nullable', 'integer', 'exists:product_types,id'],
            'sample.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'sample.*.quantity' => ['required', 'integer', 'min:0', 'max:500'],
            'sample.*.address' => ['nullable', 'integer', 'min:1', 'max:20'],
            'price_interval' => ['nullable', 'string', $intervals],
            'frequency' => ['nullable', 'string', $intervals],
            'inputs' => ['nullable', 'array'],
            'inputs.*' => ['nullable', 'numeric', 'between:-1000000000,1000000000'],
        ];
    }
}
