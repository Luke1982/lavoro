<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderTaskReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('serviceordertask.read'));
    }

    public function rules(): array
    {
        return [
            'search'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'perPage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
