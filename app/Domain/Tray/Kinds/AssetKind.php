<?php

namespace App\Domain\Tray\Kinds;

use App\Domain\Tray\Paste\AssetsOntoAsset;
use App\Domain\Tray\Paste\AssetsOntoContract;
use App\Domain\Tray\Paste\AssetsOntoCustomer;
use App\Domain\Tray\Paste\AssetsOntoLocation;
use App\Domain\Tray\Paste\AssetsOntoPlanner;
use App\Domain\Tray\Paste\AssetsOntoProject;
use App\Domain\Tray\Paste\AssetsOntoServiceOrder;
use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Machines on the bakje.
 *
 * Only a machine at the top of its tree has a customer and a location of its own;
 * a part built into another machine has both through the machine it sits in.
 */
final class AssetKind extends TrayKind
{
    public static function name(): string
    {
        return 'asset';
    }

    public static function model(): string
    {
        return Asset::class;
    }

    public static function one(): string
    {
        return 'machine';
    }

    public static function many(): string
    {
        return 'machines';
    }

    public static function places(): array
    {
        return [
            'planner' => AssetsOntoPlanner::class,
            'serviceorder' => AssetsOntoServiceOrder::class,
            'asset' => AssetsOntoAsset::class,
            'customer' => AssetsOntoCustomer::class,
            'location' => AssetsOntoLocation::class,
            'project' => AssetsOntoProject::class,
            'maintenancecontract' => AssetsOntoContract::class,
        ];
    }

    public static function visibleTo(User $user): Builder
    {
        return Asset::visibleTo($user)
            ->with(['product.brand', 'product.productType', 'linkedLocation', 'parentAsset.product.brand']);
    }

    /**
     * "Daikin RXM25R (J3309414)": brand and model, with the serial number to tell twins apart.
     *
     * @param  Asset  $asset
     */
    public static function title(Model $asset): string
    {
        $name = $asset->product?->display_name ?? 'Machine #' . $asset->id;

        return $asset->serial_number ? $name . ' (' . $asset->serial_number . ')' : $name;
    }

    /**
     * The machine a part is built into, or where a machine stands.
     *
     * @param  Asset  $asset
     */
    public static function subtitle(Model $asset): ?string
    {
        if ($asset->parentAsset) {
            return 'in ' . self::title($asset->parentAsset);
        }

        $location = $asset->resolvedLinkedLocation();

        return $location ? self::locationLabel($location) : null;
    }

    /** @param  Asset  $asset */
    public static function customerId(Model $asset): ?int
    {
        return $asset->resolvedCustomerId();
    }

    /** @param  Asset  $asset */
    public static function locationId(Model $asset): ?int
    {
        return $asset->resolvedLocationId();
    }

    /** A location by its title, or by its address when it has none. */
    public static function locationLabel(Location $location): string
    {
        return $location->title ?: $location->addressLine();
    }

    /**
     * A part goes where its machine goes. Moving one on its own, or putting it
     * under a contract, would tear it out of the machine it is built into.
     *
     * @param  Collection<int, Asset>  $assets
     * @param  string  $consequence  What that means here: "verhuist alleen mee met die machine".
     */
    public static function refuseParts(Collection $assets, string $consequence): void
    {
        $part = $assets->first(fn (Asset $asset) => $asset->parent_asset_id !== null);

        if ($part) {
            throw new Refusal(self::title($part) . ' zit in ' . self::title($part->parentAsset)
                . ' en ' . $consequence . '.');
        }
    }

    /** @param  Asset  $asset */
    protected static function withoutCustomer(Model $asset): string
    {
        return self::title($asset) . ' hoort bij geen enkele klant.';
    }

    /** A keuring for each machine, and a combined one for each of its parts. */
    protected static function serviceOrderContents(Collection $assets): array
    {
        return ['asset_ids' => $assets->pluck('id')->all()];
    }

    protected static function serviceOrderDescription(Collection $assets): string
    {
        return $assets->count() === 1
            ? 'Keuring ' . self::title($assets->first())
            : 'Keuringen: ' . $assets->map(fn (Asset $asset) => self::title($asset))->implode(', ');
    }

    /** @param  Asset  $asset */
    protected static function card(Model $asset): array
    {
        return ['url' => '/assets/' . $asset->id];
    }
}
