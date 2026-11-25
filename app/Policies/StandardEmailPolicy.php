<?php

namespace App\Policies;

use App\Models\StandardEmail;
use App\Models\User;

class StandardEmailPolicy
{
    /** Standard e-mails are only shown on the page that manages them. */
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, StandardEmail $standard_email): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('standardemail.manage');
    }
}
