<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user()
 * @method \App\Models\DocumentCategory route(string $key = null)
 */
class DocumentCategoryDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('documentcategory'));
    }

    public function rules(): array
    {
        return [];
    }
}
