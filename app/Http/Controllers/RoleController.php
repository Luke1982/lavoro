<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        return inertia('Roles/IndexPage', [
            /** Our own role does not belong in a customer's roles screen. */
            'roles' => Role::assignable()
                ->with(['users:id,name,email', 'permissions:id'])
                ->orderBy('name')
                ->get(),
            'allUsers' => User::orderBy('name')->get(['id', 'name', 'email']),
            // Use label as the combobox display name
            'allPermissions' => Permission::orderBy('label')->get(['id', 'label as name', 'name as key']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            /**
             * The name of our own role is forbidden. Without this a customer's
             * admin creates a role 'superadmin' themselves and has everything,
             * because the gate looks at the name.
             */
            'name' => ['required', 'string', 'max:255', 'unique:roles,name', 'not_in:' . Role::SUPERADMIN],
        ]);

        $role = Role::create($data);

        return redirect()->route('roles.index')
            ->with('success', 'Rol aangemaakt.')
            ->with('extra', $role);
    }

    public function update(Request $request, Role $role)
    {
        /** Whoever may not see it may not fill it with users either. */
        abort_if($role->name === Role::SUPERADMIN, 404);

        $data = $request->validate([
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        if (array_key_exists('user_ids', $data)) {
            $role->users()->sync($data['user_ids'] ?? []);
        }

        if (array_key_exists('permission_ids', $data)) {
            $role->permissions()->sync($data['permission_ids'] ?? []);
        }

        return redirect()->back()->with('success', 'Rol bijgewerkt.');
    }
}
