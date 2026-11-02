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
     * Filling in the serial of the machine in front of you, from the job you are
     * doing on it. Narrower than the machine's own form on purpose: whoever may
     * edit machines outright passes, and a monteur who may touch nothing else on
     * the record can still read the plate of a machine that sits on one of their
     * own open werkbonnen.
     *
     * A bundle, and anything of a product that is not registreerbaar, has no
     * serial to fill in -- so nobody may, and every screen asks this one method
     * rather than repeating the two halves of that rule.
     */
    public function setSerial(User $user, Asset $asset): bool
    {
        if (!$asset->product?->requiresSerial()) {
            return false;
        }

        if ($user->isAdmin() || $user->hasPermission('asset.update')) {
            return true;
        }

        return $user->hasPermission('asset.set_serial.relevant.serviceorder')
            && in_array($asset->id, $user->relevantAssetIds());
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
