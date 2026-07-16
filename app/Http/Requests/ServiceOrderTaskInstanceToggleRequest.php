<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderTaskInstanceToggleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin()
            || $user->hasPermission('serviceordertaskinstance.open_close')
            || $user->hasPermission('serviceordertaskinstance.update'));
    }

    public function rules(): array
    {
        return [
            'is_complete' => ['required', 'boolean'],
        ];
    }
}
