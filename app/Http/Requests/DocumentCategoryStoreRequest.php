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
 */
class DocumentCategoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', DocumentCategory::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:document_categories,name',
            'color' => ['required', 'string', Rule::in(DocumentCategory::COLORS)],
        ];
    }
}
