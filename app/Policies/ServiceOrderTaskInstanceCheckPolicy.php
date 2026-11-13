<?php

namespace App\Policies;

use App\Models\ServiceOrderTaskInstanceCheck;
use App\Models\User;

class ServiceOrderTaskInstanceCheckPolicy
{
    /**
     * Whoever may tick the task off may tick its checks.
     */
    public function tick(User $user, ServiceOrderTaskInstanceCheck $check): bool
    {
        return $user->hasPermission('serviceordertaskinstance.open_close')
            || $user->hasPermission('serviceordertaskinstance.update');
    }
}
