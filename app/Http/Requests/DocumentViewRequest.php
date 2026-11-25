<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Downloading or previewing a document: one on a record this person may read.
 *
 * @method \App\Models\User user()
 */
class DocumentViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('document'));
    }

    public function rules(): array
    {
        return [];
    }
}
