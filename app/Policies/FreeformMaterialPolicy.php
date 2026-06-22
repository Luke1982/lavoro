<?php

namespace App\Policies;

use App\Models\FreeformMaterial;
use App\Models\User;

class FreeformMaterialPolicy
{
    public function view(User $user, FreeformMaterial $freeformMaterial): bool
    {
        return $user->hasPermission('freeformmaterial.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('freeformmaterial.create');
    }

    public function update(User $user, FreeformMaterial $freeformMaterial): bool
    {
        return $user->hasPermission('freeformmaterial.update');
    }

    public function delete(User $user, FreeformMaterial $freeformMaterial): bool
    {
        return $user->hasPermission('freeformmaterial.delete');
    }
}
