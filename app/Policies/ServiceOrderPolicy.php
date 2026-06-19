<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;

class ServiceOrderPolicy
{
    public function update(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.update');
    }

    public function complete(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.close')
            || $user->hasPermission('serviceorder.mark_partially_complete');
    }

    public function updateStage(User $user, ServiceOrder $serviceOrder, ServiceOrderStage $stage): bool
    {
        if ($user->hasPermission('serviceorder.update')) {
            return true;
        }

        if ($user->hasPermission('serviceorder.close') && $stage->is_closed_state) {
            return true;
        }

        return $user->hasPermission('serviceorder.mark_partially_complete') && $stage->is_incomplete_state;
    }

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
