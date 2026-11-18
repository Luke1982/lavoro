<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('catalog_import'));
    }

    public function rules(): array
    {
        return [];
    }
}
