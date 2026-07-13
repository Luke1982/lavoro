<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractDetachAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('detachAsset', $this->route('maintenancecontract'));
    }

    public function rules(): array
    {
        return [];
    }
}
