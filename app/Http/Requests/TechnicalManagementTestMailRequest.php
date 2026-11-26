<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A test mail from technisch beheer: MajorLabel's own, so only a super admin.
 *
 * @method \App\Models\User|null user(string $guard = null)
 */
class TechnicalManagementTestMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
