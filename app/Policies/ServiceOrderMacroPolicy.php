<?php

namespace App\Policies;

use App\Models\User;

class ServiceOrderMacroPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPermission('serviceordermacro.manage');
    }

    /** Whoever may change werkbonnen may run a macro on one; each step still needs its own right. */
    public function run(User $user): bool
    {
        return $user->hasPermission('serviceorder.update');
    }
}
