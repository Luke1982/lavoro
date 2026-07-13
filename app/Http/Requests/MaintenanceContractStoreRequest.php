<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use App\Models\MaintenanceContract;
use App\Rules\DbRange;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MaintenanceContract::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'price' => ['required', 'numeric', 'min:0', DbRange::decimal(10, 2)],
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
        ];
    }
}
