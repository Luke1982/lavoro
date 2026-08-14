<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractTemplateDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('maintenancecontracttemplate'));
    }

    public function rules(): array
    {
        return [];
    }
}
