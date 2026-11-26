<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hanging a storing on a werkbon: both have to be in sight of this person.
 *
 * @method \App\Models\User|null user(string $guard = null)
 */
class TicketAttachToServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('attachToServiceOrder', $this->route('ticket'))
            && $this->user()->can('view', $this->route('serviceorder'));
    }

    public function rules(): array
    {
        return [];
    }
}
