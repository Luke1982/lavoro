<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $title
 * @method array user()
 * @method \App\Models\Document route(string $key = null)
 */
class DocumentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
        ];
    }
}
