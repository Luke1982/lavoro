<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user(string $guard = null)
 */
class TicketDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', Ticket::class);
    }

    public function rules(): array
    {
        return [];
    }
}
