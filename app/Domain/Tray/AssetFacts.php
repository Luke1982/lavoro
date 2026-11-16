<?php

namespace App\Domain\Tray;

use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use Illuminate\Support\Collection;

/**
 * What the bakje needs to know about a machine: what to call it, whose it is and
 * where it stands.
 *
 * Only a machine at the top of its tree has a customer and a location of its own;
 * a part built into another machine has both through the machine it sits in.
 */
final class AssetFacts
{
    /** "Daikin RXM25R (J3309414)": brand and model, with the serial number to tell twins apart. */
    public static function title(Asset $asset): string
    {
        $name = $asset->product?->display_name ?? 'Machine #' . $asset->id;

        return $asset->serial_number ? $name . ' (' . $asset->serial_number . ')' : $name;
    }

    public static function customerId(Asset $asset): ?int
    {
        return $asset->resolvedCustomerId();
    }

    public static function locationName(Asset $asset): ?string
    {
        $location = $asset->resolvedLinkedLocation();

        return $location ? self::locationLabel($location) : null;
    }

    /** A location by its title, or by its address when it has none. */
    public static function locationLabel(Location $location): string
    {
        return $location->title ?: $location->addressLine();
    }

    /**
     * The one customer all of these machines belong to, or a refusal that says
     * why there is none.
     *
     * @param  Collection<int, Asset>  $assets
     */
    public static function sharedCustomer(Collection $assets): Customer
    {
        $customer_ids = $assets->map(function (Asset $asset) {
            $customer_id = self::customerId($asset);

            if ($customer_id === null) {
                throw new Refusal(self::title($asset) . ' hoort bij geen enkele klant.');
            }

            return $customer_id;
        })->unique();

        if ($customer_ids->count() > 1) {
            throw new Refusal('Deze machines horen bij verschillende klanten. '
                . 'Pak ze los uit de stapel en plak ze per klant.');
        }

        return Customer::findOrFail($customer_ids->first());
    }

    /**
     * Where the werkbon is, when every machine stands in the same place.
     *
     * @param  Collection<int, Asset>  $assets
     */
    public static function sharedLocationId(Collection $assets): ?int
    {
        $location_ids = $assets->map(fn (Asset $asset) => $asset->resolvedLocationId())->unique();

        return $location_ids->count() === 1 ? $location_ids->first() : null;
    }

    /**
     * What a new werkbon for these machines says it is for.
     *
     * @param  Collection<int, Asset>  $assets
     */
    public static function serviceOrderDescription(Collection $assets): string
    {
        $titles = $assets->map(fn (Asset $asset) => self::title($asset));

        $description = $titles->count() === 1
            ? 'Keuring ' . $titles->first()
            : 'Keuringen: ' . $titles->implode(', ');

        return mb_strimwidth($description, 0, 255, '…');
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @return array<int, array{title: string, subtitle: ?string}>
     */
    public static function listed(Collection $assets): array
    {
        return $assets->map(fn (Asset $asset) => [
            'title' => self::title($asset),
            'subtitle' => self::locationName($asset),
        ])->values()->all();
    }

    /**
     * A part goes where its machine goes. Moving or transferring one on its own
     * would tear it out of the machine it is built into.
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
}
