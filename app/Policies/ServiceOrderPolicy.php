<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\User;

class ServiceOrderPolicy
{
    public function viewMaterials(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('materiable.read.serviceorder');
    }

    public function attachMaterial(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('materiable.create.serviceorder');
    }

    public function updateMateriable(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('materiable.update.serviceorder');
    }

    public function detachMaterial(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('materiable.delete.serviceorder');
    }
}
