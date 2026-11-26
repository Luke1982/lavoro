<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LimitsBulkSelection;
use App\Http\Requests\Concerns\SplitsDocumentsByRight;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * @property int[] $ids
 * @property int|null $document_category_id
 *
 * @method \App\Models\User user()
 */
class DocumentBulkCategoryRequest extends FormRequest
{
    use LimitsBulkSelection;
    use SplitsDocumentsByRight;

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

    /** @return array{0: Collection<int, Document>, 1: Collection<int, Document>} the ones this person may change, and the rest */
    public function documents(): array
    {
        return $this->documentsSplitBy('update');
    }

    public function messages(): array
    {
        return $this->bulkSelectionMessages('ids', 'documenten', 'een categorie geven');
    }
}
