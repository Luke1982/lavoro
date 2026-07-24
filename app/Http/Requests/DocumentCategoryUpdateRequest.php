<?php

namespace App\Http\Requests;

use App\Models\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property string $name
 * @property string $color
 *
 * @method \App\Models\User user()
 * @method \App\Models\DocumentCategory route(string $key = null)
 */
class DocumentCategoryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('documentcategory'));
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('document_categories', 'name')->ignore($this->route('documentcategory')),
            ],
            'color' => ['required', 'string', Rule::in(DocumentCategory::COLORS)],
        ];
    }
}
