<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Models\UserUnavailability;

class UserUnavailabilityPolicy
{
    /**
     * Read in two places: the planner, which shows everyone's to whoever may read
     * appointments, and a colleague's own page, for whoever may read colleagues.
     */
    public function viewAny(User $auth_user): bool
    {
        return $auth_user->can('viewAny', Event::class) || $auth_user->can('viewAny', User::class);
    }

    public function view(User $auth_user, UserUnavailability $unavailability): bool
    {
        return UserUnavailability::visibleTo($auth_user)->whereKey($unavailability->id)->exists();
    }

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
