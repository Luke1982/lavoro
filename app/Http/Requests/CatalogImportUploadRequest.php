<?php

namespace App\Http\Requests;

use App\Enums\CatalogImportKind;
use App\Models\CatalogImport;
use Illuminate\Foundation\Http\FormRequest;

class CatalogImportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $kind = $this->route('kind');

        return $kind instanceof CatalogImportKind
            && $this->user()->can('create', [CatalogImport::class, $kind]);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv,ods', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Kies een bestand.',
            'file.file' => 'Het bestand is niet goed aangekomen.',
            'file.extensions' => 'Gebruik een Excel-bestand (.xlsx of .xls), een CSV- of een ODS-bestand.',
            'file.max' => 'Het bestand mag niet groter zijn dan 10 MB.',
        ];
    }
}
