<?php

namespace App\Http\Requests;

use App\Models\Assistant;
use Illuminate\Foundation\Http\FormRequest;

class AssistantPromptStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use', Assistant::class) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'question' => ['required', 'string', 'min:2', 'max:2000'],
            /** Null keeps it on every page; a pattern pins it to one kind. */
            'context' => ['nullable', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.required' => 'Geef de vraag een korte naam voor op de knop.',
            'label.max' => 'Die naam past niet op een knop; houd het kort.',
            'question.required' => 'Geef de vraag zelf op.',
        ];
    }
}
