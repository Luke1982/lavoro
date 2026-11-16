<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TicketDetachFromServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('detachFromServiceOrder', $this->route('ticket')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
