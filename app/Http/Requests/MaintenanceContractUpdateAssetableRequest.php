<?php

namespace App\Http\Requests;

use App\Enums\ContractInterval;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractUpdateAssetableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateAssetable', $this->route('maintenancecontract'));
    }

    /**
     * frequency_days has no required_if against frequency: the widget's combobox
     * saves frequency the instant "Aangepast (dagen)" is picked, before the
     * day-count input becomes visible, so the day count always arrives in its
     * own later PUT rather than alongside the interval change.
     */
    public function rules(): array
    {
        $maintenancecontract = $this->route('maintenancecontract');
        $manage_per_asset = (bool) $maintenancecontract?->manage_frequency_per_asset;

        return [
            'frequency' => [
                $manage_per_asset ? 'sometimes' : 'prohibited',
                'nullable', 'string', 'in:' . ContractInterval::validationString(),
            ],
            'frequency_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
