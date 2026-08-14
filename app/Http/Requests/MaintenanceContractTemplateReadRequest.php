<?php

namespace App\Http\Requests;

use App\Models\MaintenanceContractTemplate;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceContractTemplateReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('maintenancecontracttemplate');

        return $template
            ? $this->user()->can('view', $template)
            : $this->user()->can('viewAny', MaintenanceContractTemplate::class);
    }

    public function rules(): array
    {
        return [];
    }
}
