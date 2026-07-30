<?php

namespace App\Http\Requests;

use App\Models\Assistant;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Carrying on after a button was clicked.
 *
 * There is no question here, and that is the point: clicking "bevestigen" is an
 * answer. The question the model gets is written by us, from what was actually
 * carried out, so nothing about what happens next comes from the browser beyond
 * the conversation it already had.
 */
class AssistantContinueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use', Assistant::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'string', 'max:2048'],
            /** Which thread this belongs to, so the turns can be read back together. */
            'conversation' => ['nullable', 'uuid'],
            'history' => ['required', 'array', 'min:1', 'max:6'],
            'history.*.question' => ['required', 'string', 'max:2000'],
            'history.*.answer' => ['required', 'string', 'max:8000'],
        ];
    }
}
