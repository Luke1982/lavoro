<?php

namespace App\Policies;

use App\Models\MaintenanceContract;
use App\Models\ServiceOrder;
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

    /**
     * A werkbon made from a contract is a werkbon all the same, so making one takes the
     * right to create werkbonnen as well as the right to generate them.
     */
    public function generateServiceOrders(User $user, MaintenanceContract $maintenanceContract): bool
    {
        return $user->hasPermission('maintenancecontract.generate') && $user->can('create', ServiceOrder::class);
    }

    /** Switching automatic generation on makes werkbonnen later, without anyone pressing a button. */
    public function autoGenerate(User $user): bool
    {
        return $user->can('create', ServiceOrder::class);
    }

    /** Price and price interval. Asked of the class: the answer is the same for every contract. */
    public function seeFinancials(User $user): bool
    {
        return $user->hasPermission('maintenancecontract.see_financials');
    }
}
