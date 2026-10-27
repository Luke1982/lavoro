<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('user.read');
    }

    /**
     * MajorLabel's accounts are invisible to the customer (global scope) and
     * untouchable as well: the scope keeps lists clean, this stops a request
     * with an id in it. A super admin does not come past here -- they are
     * already served by Gate::before.
     */
    public function view(User $user, User $model): bool
    {
        return !$model->isSuperAdmin() && $user->hasPermission('user.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('user.create');
    }

    public function update(User $user, User $model): bool
    {
        return !$model->isSuperAdmin() && $user->hasPermission('user.update');
    }

    /**
     * Whoever hands out roles indirectly hands out every permission, so this is
     * separate from user.update.
     */
    public function assignRoles(User $user): bool
    {
        return $user->hasPermission('user.assign_roles');
    }

    public function delete(User $user, User $model): bool
    {
        return !$model->isSuperAdmin()
            && $user->id !== $model->id
            && $user->hasPermission('user.delete');
    }

    public function restore(User $user, User $model): bool
    {
        return !$model->isSuperAdmin() && $user->hasPermission('user.restore');
    }

    public function viewTrashed(User $user): bool
    {
        return $user->hasPermission('user.delete') || $user->hasPermission('user.restore');
    }
}
