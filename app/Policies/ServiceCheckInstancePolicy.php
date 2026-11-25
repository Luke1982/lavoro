<?php

namespace App\Policies;

use App\Models\ServiceCheckInstance;
use App\Models\ServiceJob;
use App\Models\User;

class ServiceCheckInstancePolicy
{
    /** Keurpunten are read on their keuring, by whoever may read keuringen. */
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', ServiceJob::class);
    }

    /** A keurpunt is in sight for whoever may read keuringen and may open the werkbon its keuring is for. */
    public function view(User $user, ServiceCheckInstance $checkpoint): bool
    {
        return ServiceCheckInstance::visibleTo($user)->whereKey($checkpoint->id)->exists();
    }
}
