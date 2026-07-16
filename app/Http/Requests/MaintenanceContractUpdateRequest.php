<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use App\Rules\DbRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaintenanceContractUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('maintenancecontract'));
    }

    /**
     * price_interval_days and frequency_days deliberately have no required_if
     * against their paired interval field, unlike the Store request. The Show
     * page's combobox fields save the instant a value is picked, before the
     * day-count input even becomes visible, so interval and day-count can
     * never arrive in the same PATCH — the day count is always a separate,
     * later save.
     */
    public function rules(): array
    {
        return array_merge($this->customerChangeRules(), [
            'customer_id' => ['sometimes', 'required', 'exists:customers,id'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'price_interval' => ['sometimes', 'required', 'string', 'in:' . ContractInterval::validationString()],
            'price_interval_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'manage_frequency_per_asset' => ['sometimes', 'boolean'],
            'frequency' => [
                'sometimes', 'required_if:manage_frequency_per_asset,false',
                'nullable', 'string', 'in:' . ContractInterval::validationString(),
            ],
            'frequency_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cancelled' => ['sometimes', 'boolean'],
            'auto_generate' => ['sometimes', 'boolean'],
            'auto_generate_interval' => [
                'sometimes', 'nullable', 'string', 'in:' . ContractInterval::validationString(),
            ],
            'auto_generate_interval_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * Handing a contract to another customer would strand its machines with their old
     * owner, so the caller has to say what happens to them. Required here rather than
     * only in the modal, so the same rule holds for anything hitting the endpoint
     * directly.
     *
     * @return array<string, array<int, mixed>>
     */
    private function customerChangeRules(): array
    {
        if (!$this->changesCustomerOnContractWithAssets()) {
            return [];
        }

        return [
            'asset_strategy' => ['required', 'in:transfer'],
            'location_map' => ['nullable', 'array'],
            'location_map.*' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where(
                    fn ($query) => $query->where('customer_id', $this->input('customer_id'))
                ),
            ],
        ];
    }

    private function changesCustomerOnContractWithAssets(): bool
    {
        $contract = $this->route('maintenancecontract');

        if (!$contract || !$this->has('customer_id')) {
            return false;
        }

        if ((int) $this->input('customer_id') === (int) $contract->customer_id) {
            return false;
        }

        return $contract->assets()->exists();
    }

    public function messages(): array
    {
        return [
            'asset_strategy.required' => 'Geef aan wat er met de machines van dit contract moet gebeuren.',
            'asset_strategy.in' => 'Ongeldige keuze voor de machines van dit contract.',
            'location_map.*.exists' => 'De gekozen locatie hoort niet bij de nieuwe klant.',
        ];
    }
}
