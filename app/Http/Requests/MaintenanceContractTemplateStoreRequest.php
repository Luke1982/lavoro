<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use App\Models\MaintenanceContractTemplate;
use App\Rules\DbRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaintenanceContractTemplateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MaintenanceContractTemplate::class);
    }

    /**
     * Every contract field is optional here: a template is a starting point, and
     * one that deliberately leaves the price or the frequency open is useful. The
     * contract's own request still requires them when the contract is saved.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('maintenance_contract_templates', 'name')],
            'title' => ['nullable', 'string', 'max:255'],
            'duration_months' => ['nullable', 'integer', 'min:1', DbRange::smallInt(unsigned: true)],
            'price' => ['nullable', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'price_interval' => ['nullable', 'string', 'in:' . ContractInterval::validationString()],
            'price_interval_days' => [
                'required_if:price_interval,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1', DbRange::int(unsigned: true),
            ],
            'manage_frequency_per_asset' => ['boolean'],
            'frequency' => ['nullable', 'string', 'in:' . ContractInterval::validationString()],
            'frequency_days' => [
                'required_if:frequency,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1', DbRange::int(unsigned: true),
            ],
            'auto_generate' => ['boolean'],
            'auto_generate_interval' => ['nullable', 'string', 'in:' . ContractInterval::validationString()],
            'auto_generate_interval_days' => [
                'required_if:auto_generate_interval,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1', DbRange::int(unsigned: true),
            ],
        ];
    }
}
