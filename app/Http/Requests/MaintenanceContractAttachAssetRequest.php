<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MaintenanceContractAttachAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attachAsset', $this->route('maintenancecontract'));
    }

    public function rules(): array
    {
        $maintenancecontract = $this->route('maintenancecontract');
        $manage_per_asset = (bool) $maintenancecontract?->manage_frequency_per_asset;

        return [
            'frequency' => [
                $manage_per_asset ? 'nullable' : 'prohibited',
                'nullable', 'string', 'in:' . ContractInterval::validationString(),
            ],
            'frequency_days' => [
                'required_if:frequency,' . ContractInterval::aangepast->value,
                'nullable', 'integer', 'min:1',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $asset = $this->route('asset');
            $maintenancecontract = $this->route('maintenancecontract');
            if ($asset && $maintenancecontract && $asset->customer_id !== $maintenancecontract->customer_id) {
                $validator->errors()->add('asset', 'Deze machine hoort niet bij de klant van dit contract.');
            }
        });
    }
}
