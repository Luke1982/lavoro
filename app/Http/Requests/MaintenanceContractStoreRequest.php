<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use App\Models\MaintenanceContract;
use App\Models\MaintenanceContractTemplate;
use App\Rules\DbRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaintenanceContractStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MaintenanceContract::class)
            && (!$this->boolean('auto_generate') || $this->user()->can('autoGenerate', MaintenanceContract::class));
    }

    /**
     * A contract made from a template with a price formula gets its price from
     * that formula, so it is only asked for when there is none.
     */
    public function rules(): array
    {
        return [
            'maintenance_contract_template_id' => ['nullable', 'integer', 'exists:maintenance_contract_templates,id'],
            'customer_id' => ['required', 'exists:customers,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'price' => [
                Rule::requiredIf(fn () => !$this->templateHasPriceFormula()),
                'nullable', 'numeric', 'min:0', DbRange::decimal(10, 2),
            ],
            'price_interval' => ['required', 'string', 'in:' . ContractInterval::validationString()],
            'price_interval_days' => [
                'required_if:price_interval,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
            'manage_frequency_per_asset' => ['boolean'],
            'frequency' => [
                'required_if:manage_frequency_per_asset,false',
                'nullable', 'string', 'in:' . ContractInterval::validationString(),
            ],
            'frequency_days' => [
                'required_if:frequency,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
            'auto_generate' => ['boolean'],
            'auto_generate_interval' => ['nullable', 'string', 'in:' . ContractInterval::validationString()],
            'auto_generate_interval_days' => [
                'required_if:auto_generate_interval,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
        ];
    }

    private function templateHasPriceFormula(): bool
    {
        $template_id = $this->input('maintenance_contract_template_id');

        return $template_id !== null
            && MaintenanceContractTemplate::whereKey($template_id)->whereHas('priceFormula.latestVersion')->exists();
    }
}
