<?php

namespace App\Policies;

use App\Models\User;

class CalendarGrantPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('calendar_grant.manage');
    }
}
