<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class TicketListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && $user->hasPermission('ticket.see_all');
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'statuses' => ['sometimes', 'nullable', 'string'],
            'priorities' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
