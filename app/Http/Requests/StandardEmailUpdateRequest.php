<?php

namespace App\Http\Requests;

use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Models\StandardEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StandardEmailUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', StandardEmail::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'triggers' => 'nullable|array',
            'triggers.*.trigger' => [
                'required_with:triggers', 'string', Rule::in(array_column(EventTrigger::cases(), 'name')),
            ],
            'triggers.*.trigger_type' => [
                'required_with:triggers', 'string', Rule::in(array_column(StandardEmailTriggerType::cases(), 'name')),
            ],
            'standard_attachment_ids' => 'nullable|array',
            'standard_attachment_ids.*' => 'integer|exists:standard_attachments,id',
        ];
    }
}
