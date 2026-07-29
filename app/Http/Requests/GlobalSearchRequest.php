<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anyone signed in may type in the spotlight; each searcher decides what that
 * person is allowed to find. Guarding the endpoint on a permission instead would
 * mean picking one of eleven, and every choice is wrong for somebody.
 */
class GlobalSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
