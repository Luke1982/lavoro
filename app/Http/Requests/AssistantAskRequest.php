<?php

namespace App\Http\Requests;

use App\Models\Assistant;
use Illuminate\Foundation\Http\FormRequest;

class AssistantAskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use', Assistant::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:2', 'max:2000'],
            /**
             * Generous on purpose. A path this does not recognise is ignored, so
             * a long one should cost the question nothing — refusing it would
             * fail the whole ask over a detail that was never needed.
             */
            'page' => ['nullable', 'string', 'max:2048'],
            'history' => ['nullable', 'array', 'max:6'],
            'history.*.question' => ['required', 'string', 'max:2000'],
            'history.*.answer' => ['required', 'string', 'max:8000'],
        ];
    }

    public function messages(): array
    {
        return [
            'question.required' => 'Stel een vraag.',
            'question.max' => 'Die vraag is te lang.',
        ];
    }
}
