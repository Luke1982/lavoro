<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Making a role. The name of our own role is forbidden: without that a customer's
 * admin creates a role 'superadmin' themselves and has everything, because the
 * gate looks at the name.
 *
 * @method \App\Models\User|null user(string $guard = null)
 */
class RoleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Role::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name', 'not_in:' . Role::SUPERADMIN],
        ];
    }
}
