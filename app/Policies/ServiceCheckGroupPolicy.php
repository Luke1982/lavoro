<?php

namespace App\Policies;

use App\Models\ServiceCheckGroup;
use App\Models\User;

class ServiceCheckGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('servicecheckgroup.read');
    }

    public function view(User $user, ServiceCheckGroup $group): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('servicecheckgroup.create');
    }

    /** Asked of the class: every one of them takes the same right. */
    public function update(User $user): bool
    {
        return $user->hasPermission('servicecheckgroup.update');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('servicecheckgroup.delete');
    }
}
