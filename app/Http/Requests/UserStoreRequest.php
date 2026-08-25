<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'avatar' => 'nullable|image|max:3072',
            'plannable' => 'sometimes|boolean',
        ];

        if ($this->user()->can('assignRoles', User::class)) {
            $rules['role_ids'] = 'sometimes|array';
            $rules['role_ids.*'] = 'integer|exists:roles,id';
        }

        return $rules;
    }
}
