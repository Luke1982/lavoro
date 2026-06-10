<?php

namespace App\Policies;

use App\Models\User;

class UserRolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('userrole.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('userrole.create');
    }

    public function update(User $user): bool
    {
        return $user->hasPermission('userrole.update');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('userrole.delete');
    }
}
