<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class SupplierImportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Supplier::class);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecteer een Excel-bestand.',
            'file.file'     => 'Het geüploade bestand is ongeldig.',
            'file.mimes'    => 'Het bestand moet een Excel-bestand zijn (.xlsx of .xls).',
            'file.max'      => 'Het bestand mag niet groter zijn dan 10 MB.',
        ];
    }
}
