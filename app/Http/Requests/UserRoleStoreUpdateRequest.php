<?php

namespace App\Http\Requests;

use App\Models\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRoleStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->isMethod('post') ? 'create' : 'update';

        return $this->user()?->can($ability, UserRole::class) ?? false;
    }

    public function rules(): array
    {
        $user_role = $this->route('userrole');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('user_roles', 'name')->ignore($user_role),
            ],
            'color' => ['sometimes', 'required', 'string', 'max:32'],
        ];
    }
}
