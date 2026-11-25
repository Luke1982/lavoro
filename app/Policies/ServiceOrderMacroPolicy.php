<?php

namespace App\Policies;

use App\Models\ServiceOrderMacro;
use App\Models\User;

class ServiceOrderMacroPolicy
{
    /** Macros are only shown on the page that manages them. */
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, ServiceOrderMacro $macro): bool
    {
        return $this->viewAny($user);
    }

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
