<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractGenerateServiceOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('generateServiceOrders', $this->route('maintenancecontract'));
    }

    public function rules(): array
    {
        return [];
    }
}
