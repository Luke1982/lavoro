<?php

namespace App\Policies;

use App\Models\User;

class StandardEmailPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('standardemail.manage');
    }
}
