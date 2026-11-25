<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

class TicketListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('list', Ticket::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'statuses' => ['sometimes', 'nullable', 'string'],
            'priorities' => ['sometimes', 'nullable', 'string'],
            'status_code_search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'closed_by_ids' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
