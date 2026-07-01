<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderTaskInstanceSignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('serviceordertaskinstance.open_close'));
    }

    public function rules(): array
    {
        return [
            'signed_by'        => ['required', 'string', 'max:255'],
            'signature_base64' => ['required', 'string'],
        ];
    }
}
