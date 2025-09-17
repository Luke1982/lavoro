<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class TicketDetachFromServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && $user->hasPermission('ticket.detach_from_serviceorder');
    }

    public function rules(): array
    {
        return [];
    }
}
