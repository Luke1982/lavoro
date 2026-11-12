<?php

namespace App\Support;

use App\Models\Asset;
use Illuminate\Support\Collection;

/**
 * Who owns a machine, and where it stands. A child asset carries neither of its own, so
 * both come from the root of its parent chain.
 */
class AssetOwnership
{
    private const MAX_PARENT_DEPTH = 10;

    /**
     * Maps each asset onto the customer and location of its root asset, one query per level
     * of the chain rather than one per asset. Assets whose chain leads nowhere are left out.
     *
     * @param  Collection<int, object{id: int, customer_id: ?int, parent_asset_id: ?int, location_id: ?int}>  $assets
     * @return array<int, array{customer_id: int, location_id: int|null}>
     */
    public static function resolve(Collection $assets): array
    {
        $resolved = [];
        $pending = [];

        foreach ($assets as $asset) {
            if ($asset->customer_id !== null) {
                $resolved[$asset->id] = [
                    'customer_id' => $asset->customer_id,
                    'location_id' => $asset->location_id,
                ];
            } elseif ($asset->parent_asset_id !== null) {
                $pending[$asset->id] = $asset->parent_asset_id;
            }
        }

        for ($depth = 0; !empty($pending) && $depth < self::MAX_PARENT_DEPTH; $depth++) {
            $parents = Asset::whereIn('id', array_unique(array_values($pending)))
                ->get(['id', 'customer_id', 'parent_asset_id', 'location_id'])
                ->keyBy('id');

            $unresolved = [];

            foreach ($pending as $asset_id => $parent_id) {
                $parent = $parents->get($parent_id);

                if (!$parent) {
                    continue;
                }

                if ($parent->customer_id !== null) {
                    $resolved[$asset_id] = [
                        'customer_id' => $parent->customer_id,
                        'location_id' => $parent->location_id,
                    ];
                } elseif ($parent->parent_asset_id !== null) {
                    $unresolved[$asset_id] = $parent->parent_asset_id;
                }
            }

            $pending = $unresolved;
        }

        return $resolved;
    }
}
