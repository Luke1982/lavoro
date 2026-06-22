<?php

namespace App\Http\Requests;

use App\Enums\EventCompletionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventExecutionTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('executeOwn', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    EventCompletionStatus::ongoing->value,
                    EventCompletionStatus::completed->value,
                    EventCompletionStatus::cancelled->value,
                ]),
            ],
            'signature_base64' => [
                'nullable',
                'string',
                Rule::requiredIf($this->input('status') === EventCompletionStatus::completed->value),
            ],
        ];
    }
}
