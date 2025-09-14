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
            'roles' => Role::with(['users:id,name,email', 'permissions:id'])
                ->orderBy('name')
                ->get(),
            'allUsers' => User::orderBy('name')->get(['id', 'name', 'email']),
            // Use label as the combobox display name
            'allPermissions' => Permission::orderBy('label')->get(['id', 'label as name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
        ]);

        $role = Role::create($data);

        return redirect()->route('roles.index')
            ->with('success', 'Rol aangemaakt.')
            ->with('extra', $role);
    }

    public function update(Request $request, Role $role)
    {
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
