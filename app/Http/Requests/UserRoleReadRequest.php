<?php

namespace App\Http\Requests;

use App\Models\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class UserRoleReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', UserRole::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'perPage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
