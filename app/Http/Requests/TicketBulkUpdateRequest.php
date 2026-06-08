<?php

namespace App\Http\Requests;

use App\Enums\TicketStatusses;
use Illuminate\Foundation\Http\FormRequest;

class TicketBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('ticket.change_status');
    }

    public function rules(): array
    {
        $valid_statuses = implode(',', array_map(fn($s) => $s->value, TicketStatusses::cases()));

        return [
            'ticket_ids'   => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
            'status'       => ['required', 'string', 'in:' . $valid_statuses],
        ];
    }
}
