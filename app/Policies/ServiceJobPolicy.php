<?php

namespace App\Policies;

use App\Models\User;

class ServiceJobPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('servicejob.create');
    }
}
