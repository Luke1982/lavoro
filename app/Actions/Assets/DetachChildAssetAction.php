<?php

namespace App\Actions\Assets;

use App\Domain\Signals\Assets\AssetDetachedFromParent;
use App\Domain\Signals\Signals;
use App\Models\Asset;

/**
 * Takes a machine out of the machine it hangs under, from the machine screen and
 * when a paste off the bakje is undone. A machine is owned either by a customer
 * or by a parent, so it takes over the customer of the root it hung under, and
 * that root's location unless it is given one of its own.
 */
class DetachChildAssetAction
{
    public function execute(Asset $asset, ?int $location_id = null): void
    {
        $parent = $asset->parentAsset()->firstOrFail();
        $root = $parent->rootAsset();
        $previous_product_relation_id = $asset->product_relation_id;
        $previous_productable_id = $asset->productable_id;

        $asset->update([
            'parent_asset_id' => null,
            'product_relation_id' => null,
            'productable_id' => null,
            'customer_id' => $root->customer_id,
            'location_id' => $location_id ?? $root->location_id,
        ]);

        Signals::dispatch(new AssetDetachedFromParent(
            $asset,
            $parent,
            $previous_product_relation_id,
            $previous_productable_id,
        ));
    }
}
