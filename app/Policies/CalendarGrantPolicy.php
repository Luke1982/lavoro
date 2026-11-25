<?php

namespace App\Policies;

use App\Models\CalendarGrant;
use App\Models\User;

class CalendarGrantPolicy
{
    /** The overview sits behind the admin middleware, whatever manage() allows. */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, CalendarGrant $grant): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('calendar_grant.manage');
    }
}
