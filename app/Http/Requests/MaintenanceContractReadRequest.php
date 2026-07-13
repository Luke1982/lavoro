<?php

namespace App\Http\Requests;

use App\Models\MaintenanceContract;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $maintenancecontract = $this->route('maintenancecontract');

        return $maintenancecontract
            ? $this->user()->can('view', $maintenancecontract)
            : $this->user()->can('viewAny', MaintenanceContract::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'onlyStatus' => ['sometimes', 'nullable', 'string', 'in:toekomstig,actief,verlopen,geannuleerd'],
        ];
    }
}
