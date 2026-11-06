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

    /**
     * Creating a contract means entering its price, so it takes the right to see
     * prices as well.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('maintenancecontract.create') && $this->seeFinancials($user);
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

    /** Price and price interval. Asked of the class: the answer is the same for every contract. */
    public function seeFinancials(User $user): bool
    {
        return $user->hasPermission('maintenancecontract.see_financials');
    }
}
