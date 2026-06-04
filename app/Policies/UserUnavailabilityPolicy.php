<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserUnavailability;

class UserUnavailabilityPolicy
{
    public function create(User $auth_user, User $target_user): bool
    {
        return $auth_user->hasPermission('roster.manage_all')
            || ($auth_user->hasPermission('roster.manage_own') && $auth_user->id === $target_user->id);
    }

    public function delete(User $auth_user, UserUnavailability $unavailability): bool
    {
        return $auth_user->hasPermission('roster.manage_all')
            || ($auth_user->hasPermission('roster.manage_own') && $auth_user->id === $unavailability->user_id);
    }
}
