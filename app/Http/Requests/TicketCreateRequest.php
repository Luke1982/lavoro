<?php

namespace App\Http\Requests;

use App\Enums\TicketStatusses;
use App\Enums\TicketPriorities;
use Illuminate\Foundation\Http\FormRequest;

class TicketCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'required|in:' . implode(',', array_map(fn($prio) => $prio->value, TicketPriorities::cases())),
            'status' => 'required|in:' . implode(',', array_map(fn($status) => $status->value, TicketStatusses::cases())),
            'asset_id' => 'required|exists:assets,id',
        ];
    }

    public function messages()
    {
        return [
            'subject.required' => 'Het onderwerp is verplicht.',
            'description.required' => 'De beschrijving is verplicht.',
            'priority.required' => 'De prioriteit is verplicht.',
            'status.required' => 'De status is verplicht.',
            'asset_id.required' => 'Het asset is verplicht.',
            'asset_id.exists' => 'Het opgegeven asset bestaat niet.',
        ];
    }
}
