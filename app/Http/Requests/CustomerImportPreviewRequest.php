<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CustomerImportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPermission('customer.create');
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
