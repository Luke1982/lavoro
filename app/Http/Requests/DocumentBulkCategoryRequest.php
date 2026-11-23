<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LimitsBulkSelection;
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
    use LimitsBulkSelection;

    public function authorize(): bool
    {
        return $this->user()->can('updateAny', Document::class);
    }

    public function rules(): array
    {
        return [
            'ids' => $this->bulkSelectionRules(),
            'ids.*' => 'integer|exists:documents,id',
            'document_category_id' => 'nullable|integer|exists:document_categories,id',
        ];
    }

    public function messages(): array
    {
        return $this->bulkSelectionMessages('ids', 'documenten', 'een categorie geven');
    }
}
