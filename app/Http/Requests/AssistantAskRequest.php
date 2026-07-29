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
