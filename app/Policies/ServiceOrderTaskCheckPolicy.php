<?php

namespace App\Policies;

use App\Models\ServiceOrderTaskCheck;
use App\Models\User;

/**
 * Checks and checklists are part of how werkbontaken are set up, so whoever may
 * see or change the task templates may do the same with them.
 */
class ServiceOrderTaskCheckPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('serviceordertask.read');
    }

    public function view(User $user, ServiceOrderTaskCheck $check): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('serviceordertask.update');
    }
}
