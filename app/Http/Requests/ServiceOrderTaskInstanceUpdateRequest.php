<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderTaskInstanceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->hasPermission('serviceordertaskinstance.update'));
    }

    public function rules(): array
    {
        return [
            'is_complete' => ['sometimes', 'boolean'],
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'user_role_ids' => ['sometimes', 'array'],
            'user_role_ids.*' => ['integer', 'exists:user_roles,id'],
        ];
    }
}
