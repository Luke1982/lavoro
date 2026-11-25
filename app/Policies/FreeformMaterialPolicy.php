<?php

namespace App\Policies;

use App\Models\FreeformMaterial;
use App\Models\User;

class FreeformMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('freeformmaterial.read');
    }

    /** This one line: by viewAny, on a werkbon or task this person may see (FreeformMaterial::scopeVisibleTo). */
    public function view(User $user, FreeformMaterial $freeformMaterial): bool
    {
        return FreeformMaterial::visibleTo($user)->whereKey($freeformMaterial->id)->exists();
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
