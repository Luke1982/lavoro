<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use App\Models\MaintenanceContractTemplate;
use App\Rules\DbRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaintenanceContractTemplateUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('maintenancecontracttemplate'));
    }

    /**
     * The day-count fields have no required_if against their paired interval,
     * unlike the Store request: the Show page saves each field the moment it is
     * picked, so an interval and its day count never arrive in the same PATCH.
     */
    public function rules(): array
    {
        $template = $this->route('maintenancecontracttemplate');

        return array_merge([
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('maintenance_contract_templates', 'name')->ignore($template),
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration_months' => ['sometimes', 'nullable', 'integer', 'min:1', DbRange::smallInt(unsigned: true)],
            'manage_frequency_per_asset' => ['sometimes', 'boolean'],
            'frequency' => ['sometimes', 'nullable', 'string', 'in:' . ContractInterval::validationString()],
            'frequency_days' => ['sometimes', 'nullable', 'integer', 'min:1', DbRange::int(unsigned: true)],
            'auto_generate' => ['sometimes', 'boolean'],
            'auto_generate_interval' => [
                'sometimes', 'nullable', 'string', 'in:' . ContractInterval::validationString(),
            ],
            'auto_generate_interval_days' => ['sometimes', 'nullable', 'integer', 'min:1', DbRange::int(unsigned: true)],
        ], $this->financialRules());
    }

    /**
     * Only from whoever may see them: anyone else's form loaded them empty, and
     * saving it back would wipe the real values.
     *
     * @return array<string, array<int, mixed>>
     */
    private function financialRules(): array
    {
        if (!$this->user()->can('seeFinancials', MaintenanceContractTemplate::class)) {
            return [];
        }

        return [
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'price_interval' => ['sometimes', 'nullable', 'string', 'in:' . ContractInterval::validationString()],
            'price_interval_days' => ['sometimes', 'nullable', 'integer', 'min:1', DbRange::int(unsigned: true)],
        ];
    }
}
