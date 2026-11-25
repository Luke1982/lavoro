<?php

namespace App\Policies;

use App\Models\ServiceOrderTask;
use App\Models\User;

class ServiceOrderTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('serviceordertask.read');
    }

    public function view(User $user, ServiceOrderTask $task): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('serviceordertask.create');
    }

    public function update(User $user, ServiceOrderTask $task): bool
    {
        return $user->hasPermission('serviceordertask.update');
    }

    public function delete(User $user, ServiceOrderTask $task): bool
    {
        return $user->hasPermission('serviceordertask.delete');
    }
}
