<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The import screen. Another tab or header row can be asked for while mapping,
 * to see which columns that gives before choosing it.
 */
class CatalogImportReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('catalog_import'));
    }

    public function rules(): array
    {
        return [
            'sheet' => ['nullable', 'string', 'max:255'],
            'header_row' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
