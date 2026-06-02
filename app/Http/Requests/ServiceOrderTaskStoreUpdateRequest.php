<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderTaskStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $permission = $this->isMethod('post') ? 'serviceordertask.create' : 'serviceordertask.update';
        return $user && ($user->isAdmin() || $user->hasPermission($permission));
    }

    public function rules(): array
    {
        return [
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
