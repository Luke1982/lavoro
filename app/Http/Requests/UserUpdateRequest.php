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
             * Ook bij wijzigen: iemand van binnen- naar buitendienst zetten
             * verplaatst hem naar een andere plaats uit het abonnement, en die
             * kan vol zijn. De eigen plaats telt niet mee, anders kan niemand
             * blijven staan waar hij staat.
             */
            /**
             * Een superbeheerder bezet geen plaats uit het abonnement -- dat
             * account is van MajorLabel. Hij krijgt de keuze niet te zien en
             * hoeft hem dus ook niet mee te sturen.
             */
            'seat_type' => $this->targetIsSuperAdmin()
                ? ['nullable']
                : ['required', 'in:field,office', new SeatAvailable($ignore_id)],
        ];

        $request_user = $this->user();
        if ($request_user && $request_user->can('assignRoles', User::class)) {
            $rules['role_ids'] = 'sometimes|array';
            /**
             * Alleen rollen die een klant mag toekennen. Zonder deze grens
             * plakt een aangepast verzoek het id van onze eigen rol erbij en
             * heeft die gebruiker alles.
             *
             * Een superbeheerder valt hier niet onder: die moet zijn eigen rol
             * kunnen laten staan, anders is zijn eigen profiel niet op te
             * slaan.
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
