<?php

namespace App\Policies;

use App\Models\ServiceCheckInstance;
use App\Models\User;

class ServiceCheckInstancePolicy
{
    /** A keurpunt is in sight for whoever may read keuringen and may open the werkbon its keuring is for. */
    public function view(User $user, ServiceCheckInstance $checkpoint): bool
    {
        $service_order = $checkpoint->serviceJob?->serviceOrder;

        return $user->hasPermission('servicejob.read')
            && $service_order !== null
            && $user->can('view', $service_order);
    }
}
