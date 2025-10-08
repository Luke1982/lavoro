<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Asset;

class AssetPolicy
{
    /**
     * Determine if the user can view the asset.
     */
    public function view(User $user, Asset $asset): bool
    {
        if ($user->isAdmin() || $user->hasPermission('asset.read')) {
            return true;
        }
        if ($user->hasPermission('asset.read.relevant.serviceorder')) {
            return in_array($asset->id, $user->relevantAssetIds());
        }
        return false;
    }
}
