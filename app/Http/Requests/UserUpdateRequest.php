<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $route_user = request()->route('user');
        $route_user_id = is_object($route_user) ? $route_user->id : $route_user;
        $current_user_id = optional(request()->user())->id;
        $ignore_id = $route_user_id ?: $current_user_id;

        $rules = [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($ignore_id),
            ],
            'password' => 'nullable|string|min:8',
            'avatar' => 'nullable|image|max:3072',
        ];

        $request_user = request()->user();
        if ($request_user && method_exists($request_user, 'isAdmin') && $request_user->isAdmin()) {
            $rules['role_ids'] = 'sometimes|array';
            $rules['role_ids.*'] = 'integer|exists:roles,id';
        }

        return $rules;
    }
}
