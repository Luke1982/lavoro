<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sending a document on a quote along to the customer, or no longer. Where it is
 * shared from is read from the document's own link, so nothing here names it.
 *
 * @property bool $shared
 *
 * @method \App\Models\Document|null route(string $key = null)
 * @method \App\Models\User|null user(string $guard = null)
 */
class DocumentShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('share', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'shared' => 'required|boolean',
        ];
    }
}
