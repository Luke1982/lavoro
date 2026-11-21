<?php

namespace App\Actions\Assets;

use App\Domain\Signals\Assets\AssetAttachedToParent;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\Asset;

/**
 * Hangs an existing standalone machine under another one, from the machine screen
 * and from the bakje alike. It gives up its own customer and location in the
 * process: from here on it is owned through its parent.
 */
class AttachChildAssetAction
{
    public function execute(Asset $parent, Asset $child, ?int $product_relation_id = null): void
    {
        if ($reason = $this->refusal($parent, $child)) {
            throw new Refusal($reason);
        }

        $child->update([
            'parent_asset_id' => $parent->id,
            'product_relation_id' => $product_relation_id,
            'customer_id' => null,
            'location_id' => null,
        ]);

        Signals::dispatch(new AssetAttachedToParent($child, $parent));
    }

    /** Why this machine cannot go under that one, or null when it can. */
    public function refusal(Asset $parent, Asset $child): ?string
    {
        if ($child->id === $parent->id) {
            return 'Een machine kan niet onder zichzelf hangen.';
        }

        if ($child->parent_asset_id !== null) {
            return 'Deze machine hangt al onder een andere machine.';
        }

        if ($child->customer_id !== $parent->resolvedCustomerId()) {
            return 'Deze machine hoort niet bij dezelfde klant.';
        }

        if ($this->wouldCycle($parent, $child)) {
            return 'Deze machine hangt al boven de machine waaronder je hem wilt koppelen.';
        }

        $parent_type_id = $parent->product?->productType?->id;
        $child_parent_type_id = $child->product?->productType?->parent_id;

        if ($parent_type_id === null || $child_parent_type_id !== $parent_type_id) {
            return 'Het producttype van de te koppelen machine is niet compatibel met dit apparaat. '
                . 'Alleen machines waarvan het producttype een subtype is van "'
                . ($parent->product?->productType?->name ?? 'onbekend')
                . '" kunnen worden gekoppeld.';
        }

        return null;
    }

    /**
     * Guards against hanging a machine under one of its own descendants, which would
     * cut the branch loose from any root and leave it owned by nobody.
     */
    private function wouldCycle(Asset $parent, Asset $child): bool
    {
        $node = $parent;

        while ($node->parent_asset_id !== null) {
            if ($node->parent_asset_id === $child->id) {
                return true;
            }

            $node = $node->parentAsset()->first();

            if (!$node) {
                return false;
            }
        }

        return false;
    }
}
