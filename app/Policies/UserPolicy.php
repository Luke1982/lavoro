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
