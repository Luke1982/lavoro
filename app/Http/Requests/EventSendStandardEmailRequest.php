<?php

namespace App\Http\Requests;

use App\Enums\EventTrigger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventSendStandardEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'standard_email_id' => 'required|integer|exists:standard_emails,id',
            'to' => 'required|email',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'trigger' => ['nullable', 'string', Rule::in(array_column(EventTrigger::cases(), 'name'))],
        ];
    }
}
