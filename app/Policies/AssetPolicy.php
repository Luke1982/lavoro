<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    /**
     * Determine if the user can view the asset.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('asset.create');
    }

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

    /**
     * Whether the user may see machines at all, including the narrower case of
     * only those on their own werkbonnen. Which machines those are is decided by
     * Asset::scopeVisibleTo, which follows the same two permissions.
     */
    public function listRelevant(User $user): bool
    {
        return $user->hasPermission('asset.read')
            || $user->hasPermission('asset.read.relevant.serviceorder');
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
