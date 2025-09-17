<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class TicketAttachToServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && $user->hasPermission('ticket.add_to_serviceorder');
    }

    public function rules(): array
    {
        return [];
    }
}
