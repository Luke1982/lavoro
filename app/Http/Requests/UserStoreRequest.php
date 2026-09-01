<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use App\Rules\SeatAvailable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'seat_type' => ['required', 'in:field,office', new SeatAvailable],
            'email' => ['required', 'email', 'unique:users,email', Rule::unique('central.user_tenant_lookups', 'email')],
            'password' => 'required|string|min:8',
            'avatar' => 'nullable|image|max:3072',
            'plannable' => 'sometimes|boolean',
        ];

        if ($this->user()->can('assignRoles', User::class)) {
            $rules['role_ids'] = 'sometimes|array';
            /**
             * Alleen rollen die een klant mag toekennen. Zonder deze grens
             * plakt een aangepast verzoek het id van onze eigen rol erbij en
             * heeft die gebruiker alles.
             */
            $rules['role_ids.*'] = [
                'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($query) => $query->where('name', '!=', Role::SUPERADMIN)
                ),
            ];
        }

        return $rules;
    }
}
