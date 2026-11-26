<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LimitsBulkSelection;
use App\Http\Requests\Concerns\SplitsDocumentsByRight;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * @property int[] $ids
 *
 * @method \App\Models\User user()
 */
class DocumentBulkDestroyRequest extends FormRequest
{
    use LimitsBulkSelection;
    use SplitsDocumentsByRight;

    public function authorize(): bool
    {
        return $this->user()->can('deleteAny', Document::class);
    }

    public function rules(): array
    {
        return [
            'ids' => $this->bulkSelectionRules(),
            'ids.*' => 'integer|exists:documents,id',
        ];
    }

    /** @return array{0: Collection<int, Document>, 1: Collection<int, Document>} the ones this person may remove, and the rest */
    public function documents(): array
    {
        return $this->documentsSplitBy('delete');
    }

    public function messages(): array
    {
        return $this->bulkSelectionMessages('ids', 'documenten', 'verwijderen');
    }
}
