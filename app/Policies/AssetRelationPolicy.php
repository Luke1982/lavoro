<?php

namespace App\Policies;

use App\Models\AssetRelation;
use App\Models\User;

class AssetRelationPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('assetrelation.create');
    }

    public function delete(User $user, AssetRelation $assetRelation): bool
    {
        return $user->hasPermission('assetrelation.delete');
    }
}
