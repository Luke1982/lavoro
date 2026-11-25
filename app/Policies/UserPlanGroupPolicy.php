<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserPlanGroup;

/** Plan groups are arranged by whoever plans other people's work. */
class UserPlanGroupPolicy
{
    /** Plan groups are listed to whoever arranges them. */
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, UserPlanGroup $group): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user): bool
    {
        return $user->hasAnyPermission(['event.see_all', 'event.create_others']);
    }
}
