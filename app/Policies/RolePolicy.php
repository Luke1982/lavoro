<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Read by whoever may hand out roles, the roles they pick from on the user
 * form, and by an admin. The roles screen itself stays behind the admin
 * middleware, and our own role is never shown; a super admin sees it through
 * Gate::before.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->can('assignRoles', User::class);
    }

    public function view(User $user, Role $role): bool
    {
        return $role->name !== Role::SUPERADMIN && $this->viewAny($user);
    }

    /** Making roles and choosing what is in them stays the admin's; see the user form for handing them out. */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Role $role): bool
    {
        return $user->isAdmin();
    }
}
