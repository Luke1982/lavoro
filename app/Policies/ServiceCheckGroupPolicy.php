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
}
