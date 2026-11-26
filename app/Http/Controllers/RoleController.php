<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleStoreRequest;
use App\Http\Requests\RoleUpdateRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

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

    public function store(RoleStoreRequest $request)
    {
        $role = Role::create($request->validated());

        return redirect()->route('roles.index')
            ->with('success', 'Rol aangemaakt.')
            ->with('extra', $role);
    }

    public function update(RoleUpdateRequest $request, Role $role)
    {
        /** Whoever may not see it may not fill it with users either, a super admin through Gate::before included. */
        abort_if($role->name === Role::SUPERADMIN, 404);

        $data = $request->validated();

        if (array_key_exists('user_ids', $data)) {
            $role->syncUsers($data['user_ids'] ?? []);
        }

        if (array_key_exists('permission_ids', $data)) {
            $role->syncPermissions($data['permission_ids'] ?? []);
        }

        return redirect()->back()->with('success', 'Rol bijgewerkt.');
    }
}
