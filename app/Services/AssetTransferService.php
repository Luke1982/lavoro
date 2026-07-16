<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves machines from one customer to another.
 *
 * Only root assets carry a customer_id, so handing a subtree to another customer is a
 * single write on its root — every descendant follows structurally and is never touched.
 * The tree still gets walked for the things that hang off machines at any depth: contract
 * attachments belonging to a customer that no longer owns them.
 *
 * Service jobs and tickets are deliberately absent here. They key off asset_id alone, so
 * a machine's history travels with it by construction — that is the point.
 */
class AssetTransferService
{
    /**
     * What a transfer would do, without doing it. Feeds the confirmation modal.
     *
     * @param  Collection<int, Asset>  $roots
     * @return array{assets: array<int, array{id: int, label: string, is_child: bool}>,
     *               locations: array<int, array{id: int, label: string}>,
     *               contracts: array<int, array{id: int, label: string}>}
     */
    public function preview(Collection $roots, int $new_customer_id): array
    {
        $tree = $this->treeFor($roots);
        $root_ids = $roots->pluck('id')->all();

        $locations = Location::query()
            ->whereIn('id', $roots->pluck('location_id')->filter()->unique()->values())
            ->get()
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'label' => $location->title ?: $location->addressLine(),
            ])
            ->values()
            ->all();

        return [
            'assets' => $tree
                ->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'label' => $asset->serial_number ?: ('#' . $asset->id),
                    'is_child' => !in_array($asset->id, $root_ids, true),
                ])
                ->values()
                ->all(),
            'locations' => $locations,
            'contracts' => $this->contractsLosing($tree, $new_customer_id)
                ->map(fn (MaintenanceContract $contract) => [
                    'id' => $contract->id,
                    'label' => $contract->display_title,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Asset>  $roots
     * @param  array<int, int|null>  $location_map  old location id => new location id or null
     */
    public function transfer(Collection $roots, int $new_customer_id, array $location_map): void
    {
        DB::transaction(function () use ($roots, $new_customer_id, $location_map) {
            $tree = $this->treeFor($roots);

            foreach ($roots as $root) {
                $root->update([
                    'customer_id' => $new_customer_id,
                    'location_id' => $location_map[$root->location_id] ?? null,
                ]);
            }

            foreach ($this->contractsLosing($tree, $new_customer_id) as $contract) {
                $losing = $contract->assets
                    ->whereIn('id', $tree->pluck('id'))
                    ->map(fn (Asset $asset) => $asset->serial_number ?: ('#' . $asset->id));

                $contract->assets()->detach($tree->pluck('id')->all());

                $contract->logActivity(sprintf(
                    'Machine losgekoppeld van contract door overdracht naar andere klant: %s',
                    $losing->implode(', ')
                ));
            }
        });
    }

    /**
     * The given roots plus every descendant hanging under them.
     *
     * @param  Collection<int, Asset>  $roots
     * @return Collection<int, Asset>
     */
    public function treeFor(Collection $roots): Collection
    {
        $tree = collect($roots->all());
        $frontier = $roots->pluck('id')->all();

        while (!empty($frontier)) {
            $children = Asset::whereIn('parent_asset_id', $frontier)->get();

            if ($children->isEmpty()) {
                break;
            }

            $tree = $tree->concat($children->all());
            $frontier = $children->pluck('id')->all();
        }

        return $tree->unique('id')->values();
    }

    /**
     * Contracts holding any machine in the tree that would no longer belong to the
     * contract's own customer once the transfer lands.
     *
     * @param  Collection<int, Asset>  $tree
     * @return Collection<int, MaintenanceContract>
     */
    private function contractsLosing(Collection $tree, int $new_customer_id): Collection
    {
        return MaintenanceContract::query()
            ->where('customer_id', '!=', $new_customer_id)
            ->whereHas('assets', fn ($query) => $query->whereIn('assets.id', $tree->pluck('id')))
            ->with('assets')
            ->get();
    }
}
