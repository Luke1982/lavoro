<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use App\Rules\DbRange;
use Illuminate\Foundation\Http\FormRequest;

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
        return [
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
        ];
    }
}
