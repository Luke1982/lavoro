<?php

namespace App\Http\Requests;

use App\Models\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class UserRoleDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', UserRole::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
