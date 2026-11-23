<?php

namespace App\Http\Requests;

use App\Enums\TicketStatusses;
use App\Http\Requests\Concerns\LimitsBulkSelection;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

class TicketBulkUpdateRequest extends FormRequest
{
    use LimitsBulkSelection;

    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', Ticket::class);
    }

    public function rules(): array
    {
        $valid_statuses = implode(',', array_map(fn ($s) => $s->value, TicketStatusses::cases()));

        return [
            'ticket_ids' => $this->bulkSelectionRules(),
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
            'status' => ['required', 'string', 'in:' . $valid_statuses],
        ];
    }

    public function messages(): array
    {
        return $this->bulkSelectionMessages('ticket_ids', 'storingen', 'bijwerken');
    }
}
