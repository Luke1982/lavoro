<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TicketDetachFromServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('detachFromServiceOrder', $this->route('ticket'))
            && $this->user()->can('view', $this->route('serviceorder'));
    }

    public function rules(): array
    {
        return [];
    }
}
