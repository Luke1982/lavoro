<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int[] $ids
 *
 * @method \App\Models\User user()
 */
class DocumentBulkDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('deleteAny', Document::class);
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:documents,id',
        ];
    }
}
