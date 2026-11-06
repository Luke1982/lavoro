<?php

namespace App\Policies;

use App\Models\MaintenanceContract;
use App\Models\MaintenanceContractTemplate;
use App\Models\User;

class MaintenanceContractTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('maintenancecontracttemplate.read');
    }

    public function view(User $user, MaintenanceContractTemplate $maintenanceContractTemplate): bool
    {
        return $user->hasPermission('maintenancecontracttemplate.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('maintenancecontracttemplate.create');
    }

    public function update(User $user, MaintenanceContractTemplate $maintenanceContractTemplate): bool
    {
        return $user->hasPermission('maintenancecontracttemplate.update');
    }

    public function delete(User $user, MaintenanceContractTemplate $maintenanceContractTemplate): bool
    {
        return $user->hasPermission('maintenancecontracttemplate.delete');
    }

    /**
     * A template's price is the price the contracts made from it get, so it sits
     * behind the contracts' own financial permission.
     */
    public function seeFinancials(User $user): bool
    {
        return $user->can('seeFinancials', MaintenanceContract::class);
    }
}
