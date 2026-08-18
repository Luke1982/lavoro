<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User|null user(string $guard = null)
 */
class TicketInfoRequestReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('requestCustomerInfo', $this->route('ticket'));
    }

    public function rules(): array
    {
        return [];
    }

    public function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
