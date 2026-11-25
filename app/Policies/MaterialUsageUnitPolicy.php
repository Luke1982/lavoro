<?php

namespace App\Policies;

use App\Models\MaterialUsageUnit;
use App\Models\User;

class MaterialUsageUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('materialusageunit.read');
    }

    public function view(User $user, MaterialUsageUnit $unit): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('materialusageunit.create');
    }

    public function update(User $user, MaterialUsageUnit $unit): bool
    {
        return $user->hasPermission('materialusageunit.update');
    }

    public function delete(User $user, MaterialUsageUnit $unit): bool
    {
        return $user->hasPermission('materialusageunit.delete');
    }
}
