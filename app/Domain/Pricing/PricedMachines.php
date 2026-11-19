<?php

namespace App\Domain\Pricing;

use App\Domain\Contracts\ContractLabels;
use App\Enums\ContractInterval;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Support\Collection;

/**
 * The machines a price formula is worked out over, in the shape the calculator
 * reads: the machines on a contract, or the made-up ones of a trial run.
 *
 * Keys are never plain numbers, so they can be compared strictly without PHP
 * turning one into an array index along the way.
 */
final class PricedMachines
{
    /** @return array<int, array<string, mixed>> */
    public static function forContract(MaintenanceContract $contract): array
    {
        $assets = $contract->assets()->with([
            'product.brand',
            'product.productAttributeValueables.value',
            'linkedLocation',
        ])->get();

        return $assets->map(function (Asset $asset) use ($contract) {
            [$frequency, $days] = $contract->effectiveFrequency($asset->pivot);

            return [
                'key' => 'asset-' . $asset->id,
                'label' => ContractLabels::asset($asset),
                'address_key' => $asset->location_id ? 'location-' . $asset->location_id : 'customer',
                'address_label' => $asset->linkedLocation?->title ?? 'Adres van de klant',
                'visits_per_year' => self::timesPerYear($frequency, $days),
                ...self::productFacts($asset->product),
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array{product_type_id?: ?int, product_id?: ?int, quantity?: ?int, address?: ?int}>  $sample
     * @return array<int, array<string, mixed>>
     */
    public static function forSample(array $sample, ?string $frequency = null, ?int $frequency_days = null): array
    {
        $products = Product::with(['brand', 'productAttributeValueables.value'])
            ->whereKey(collect($sample)->pluck('product_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $type_names = ProductType::whereKey(collect($sample)->pluck('product_type_id')->filter()->unique())
            ->pluck('name', 'id');

        $machines = [];

        foreach (array_values($sample) as $line_index => $line) {
            $product = isset($line['product_id']) ? $products->get((int) $line['product_id']) : null;
            $type_id = $product?->product_type_id ?? ($line['product_type_id'] ?? null);
            $address = max(1, (int) ($line['address'] ?? 1));
            $facts = $product ? self::productFacts($product) : self::productFacts(null, $type_id);

            for ($i = 1; $i <= (int) ($line['quantity'] ?? 0); $i++) {
                $machines[] = [
                    'key' => 'sample-' . $line_index . '-' . $i,
                    'label' => $product?->display_name ?? $type_names->get((int) $type_id) ?? 'Machine',
                    'address_key' => 'address-' . $address,
                    'address_label' => 'Adres ' . $address,
                    'visits_per_year' => self::timesPerYear($frequency, $frequency_days),
                    ...$facts,
                ];
            }
        }

        return $machines;
    }

    /** How often something with this interval happens in a year; nothing set counts as once. */
    public static function timesPerYear(?string $interval, ?int $days): float
    {
        return match ($interval) {
            ContractInterval::maandelijks->value => 12.0,
            ContractInterval::halfjaarlijks->value => 2.0,
            ContractInterval::aangepast->value => $days ? round(365 / $days, 4) : 1.0,
            default => 1.0,
        };
    }

    /** @return array<string, mixed> */
    private static function productFacts(?Product $product, ?int $type_id = null): array
    {
        $attributes = $product
            ? self::attributesOf($product->productAttributeValueables ?? collect())
            : [];

        return [
            'product_id' => $product?->id,
            'product_type_id' => $product?->product_type_id ?? $type_id,
            'brand_id' => $product?->brand_id,
            'attributes' => $attributes,
            'retail_price' => (float) ($product?->retail_price ?? 0),
            'purchase_price' => (float) ($product?->purchase_price ?? 0),
        ];
    }

    /** @return array<int, string> attribute id → value */
    private static function attributesOf(Collection $valueables): array
    {
        return $valueables
            ->filter(fn ($valueable) => $valueable->product_attribute_id && $valueable->value)
            ->mapWithKeys(fn ($valueable) => [
                (int) $valueable->product_attribute_id => (string) $valueable->value->value,
            ])
            ->all();
    }
}
