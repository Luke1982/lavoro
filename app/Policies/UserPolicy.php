<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('user.read');
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasPermission('user.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('user.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermission('user.update');
    }

    /**
     * Wie rollen uitdeelt deelt indirect alle rechten uit, dus dit staat los
     * van user.update.
     */
    public function assignRoles(User $user): bool
    {
        return $user->hasPermission('user.assign_roles');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->id !== $model->id && $user->hasPermission('user.delete');
    }

    public function restore(User $user, User $model): bool
    {
        return $user->hasPermission('user.restore');
    }

    public function viewTrashed(User $user): bool
    {
        return $user->hasPermission('user.delete') || $user->hasPermission('user.restore');
    }
}
