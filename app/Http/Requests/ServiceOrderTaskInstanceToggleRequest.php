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
            'is_complete'              => ['required', 'boolean'],
            'assets'                   => ['sometimes', 'array'],
            'assets.*.product_id'      => ['required_with:assets', 'integer', 'exists:products,id'],
            'assets.*.serial_number'   => ['required_with:assets', 'string', 'max:255'],
        ];
    }
}
