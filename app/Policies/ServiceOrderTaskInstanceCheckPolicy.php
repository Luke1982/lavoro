<?php

namespace App\Policies;

use App\Models\ServiceOrderTaskInstanceCheck;
use App\Models\User;

class ServiceOrderTaskInstanceCheckPolicy
{
    /**
     * Whoever may tick the task off may tick its checks, on a werkbon and a task
     * they get to see. A closed werkbon is refused where the tick is made, with
     * the reason.
     */
    public function tick(User $user, ServiceOrderTaskInstanceCheck $check): bool
    {
        return ($user->hasPermission('serviceordertaskinstance.open_close')
            || $user->hasPermission('serviceordertaskinstance.update'))
            && $user->can('reach', $check->instance);
    }
}
