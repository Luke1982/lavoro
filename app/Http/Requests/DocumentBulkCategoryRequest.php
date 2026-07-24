<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int[] $ids
 * @property int|null $document_category_id
 *
 * @method \App\Models\User user()
 */
class DocumentBulkCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateAny', Document::class);
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:documents,id',
            'document_category_id' => 'nullable|integer|exists:document_categories,id',
        ];
    }
}
