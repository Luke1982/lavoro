<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user(string $guard = null)
 */
class TicketDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('ticket.delete');
    }

    public function rules(): array
    {
        return [];
    }
}
