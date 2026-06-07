<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;

class ServiceOrderPolicy
{
    public function changeStage(User $user, ServiceOrder $serviceOrder, ?ServiceOrderStage $newStage): bool
    {
        if ($user->hasPermission('serviceorderstage.update')) {
            return true;
        }

        $new_is_closed = $newStage?->is_closed_state === true;

        if ($new_is_closed && ! $serviceOrder->is_closed) {
            return $user->hasPermission('serviceorder.close');
        }

        if (! $new_is_closed && $serviceOrder->is_closed) {
            return $user->hasPermission('serviceorder.reopen');
        }

        return false;
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
