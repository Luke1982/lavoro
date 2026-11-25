<?php

namespace App\Policies;

use App\Models\ServiceOrderTaskInstance;
use App\Models\ServiceOrderTaskInstanceCheck;
use App\Models\User;

class ServiceOrderTaskInstanceCheckPolicy
{
    /** A task's checks are read with the task. */
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', ServiceOrderTaskInstance::class);
    }

    /** This one check: on a task this person may see, as reach() asks before a tick. */
    public function view(User $user, ServiceOrderTaskInstanceCheck $check): bool
    {
        return ServiceOrderTaskInstanceCheck::visibleTo($user)->whereKey($check->id)->exists();
    }

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
