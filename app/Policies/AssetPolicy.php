<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    /**
     * Determine if the user can view the asset.
     */
    public function view(User $user, Asset $asset): bool
    {
        if ($user->hasPermission('asset.read')) {
            return true;
        }
        if ($user->hasPermission('asset.read.relevant.serviceorder')) {
            return in_array($asset->id, $user->relevantAssetIds());
        }

        return false;
    }

    /**
     * Determine if the user can view any assets.
     */
    public function list(User $user): bool
    {
        return $user->hasPermission('asset.read');
    }

    public function update(User $user, Asset $asset): bool
    {
        return $user->isAdmin() || $user->hasPermission('asset.update');
    }

    /**
     * Hanging a machine under another one, and cutting it loose again. These kept the
     * assetrelation.* permission names when asset_relations folded into assets, because
     * the names are already granted to roles in the database.
     */
    public function attachChild(User $user, Asset $asset): bool
    {
        return $user->hasPermission('assetrelation.create');
    }

    public function detachParent(User $user, Asset $asset): bool
    {
        return $user->hasPermission('assetrelation.delete');
    }
}
