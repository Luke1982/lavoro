<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderStageStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $permission = $this->isMethod('post') ? 'serviceorderstage.create' : 'serviceorderstage.update';
        return $user && ($user->isAdmin() || $user->hasPermission($permission));
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
