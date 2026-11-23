<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LimitsBulkSelection;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int[] $ids
 *
 * @method \App\Models\User user()
 */
class DocumentBulkDestroyRequest extends FormRequest
{
    use LimitsBulkSelection;

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

    public function messages(): array
    {
        return $this->bulkSelectionMessages('ids', 'documenten', 'verwijderen');
    }
}
