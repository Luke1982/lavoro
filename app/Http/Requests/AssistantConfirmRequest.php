<?php

namespace App\Http\Requests;

use App\Models\Assistant;
use Illuminate\Foundation\Http\FormRequest;

class AssistantConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use', Assistant::class) ?? false;
    }

    /**
     * Only the token is accepted. What is to be done lives inside it, so there is
     * nothing else here worth validating — and nothing else that could be used to
     * alter what was agreed to.
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:8000'],
        ];
    }
}
