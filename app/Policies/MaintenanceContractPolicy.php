<?php

namespace App\Policies;

use App\Models\MaintenanceContract;
use App\Models\User;

class MaintenanceContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('maintenancecontract.read');
    }

    public function view(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('maintenancecontract.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('maintenancecontract.create');
    }

    public function update(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('maintenancecontract.update');
    }

    public function delete(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('maintenancecontract.delete');
    }

    public function attachAsset(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('assetable.create.maintenancecontract');
    }

    public function updateAssetable(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('assetable.update.maintenancecontract');
    }

    public function detachAsset(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('assetable.delete.maintenancecontract');
    }

    public function generateServiceOrders(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('maintenancecontract.generate');
    }
}
