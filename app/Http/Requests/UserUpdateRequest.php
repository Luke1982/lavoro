<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use App\Rules\SeatAvailable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $route_user = $this->route('user');
        if ($route_user) {
            return $this->user()->can('update', $route_user);
        }

        return $this->user() !== null;
    }

    private function targetIsSuperAdmin(): bool
    {
        $route_user = $this->route('user');

        $user = is_object($route_user)
            ? $route_user
            : User::withoutGlobalScopes()->find($route_user ?: optional($this->user())->id);

        return $user?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        $route_user = $this->route('user');
        $route_user_id = is_object($route_user) ? $route_user->id : $route_user;
        $current_user_id = optional($this->user())->id;
        $ignore_id = $route_user_id ?: $current_user_id;

        $rules = [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($ignore_id),
                Rule::unique('central.user_tenant_lookups', 'email')->ignore(
                    optional(User::find($ignore_id))->email, 'email'
                ),
            ],
            'password' => 'nullable|string|min:8',
            'avatar' => 'nullable|image|max:3072',
            /**
             * On updating too: moving someone from office to field staff moves
             * them to another kind of seat from the subscription, and that one
             * can be full. Their own seat does not count, otherwise nobody can
             * stay where they are.
             */
            /**
             * A super admin occupies no seat from the subscription -- that
             * account belongs to MajorLabel. They do not get to see the choice
             * and therefore do not have to send it along.
             */
            'seat_type' => $this->targetIsSuperAdmin()
                ? ['nullable']
                : ['required', 'in:field,office', new SeatAvailable($ignore_id)],
        ];

        $request_user = $this->user();
        if ($request_user && $request_user->can('assignRoles', User::class)) {
            $rules['role_ids'] = 'sometimes|array';
            /**
             * Only roles a customer may grant. Without this limit a doctored
             * request adds the id of our own role and that user has
             * everything.
             *
             * A super admin does not fall under it: they have to be able to
             * keep their own role, otherwise their own profile cannot be
             * saved.
             */
            $rules['role_ids.*'] = [
                'integer',
                $request_user?->isSuperAdmin()
                    ? Rule::exists('roles', 'id')
                    : Rule::exists('roles', 'id')->where(
                        fn ($query) => $query->where('name', '!=', Role::SUPERADMIN)
                    ),
            ];
        }
        if ($request_user && method_exists($request_user, 'isAdmin') && $request_user->isAdmin()) {
            $rules['plannable'] = 'sometimes|boolean';
        }

        return $rules;
    }
}
