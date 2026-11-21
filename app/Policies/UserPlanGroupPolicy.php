<?php

namespace App\Policies;

use App\Models\User;

/** Plan groups are arranged by whoever plans other people's work. */
class UserPlanGroupPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasAnyPermission(['event.see_all', 'event.create_others']);
    }
}
