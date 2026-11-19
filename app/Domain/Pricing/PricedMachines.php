<?php

namespace App\Domain\Pricing;

use App\Domain\Contracts\ContractLabels;
use App\Enums\ContractInterval;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        $type_paths = ProductType::pathMap();

        return $assets->map(function (Asset $asset) use ($contract, $type_paths) {
            [$frequency, $days] = $contract->effectiveFrequency($asset->pivot);
            $address = $asset->linkedLocation?->title ?? 'Adres van de klant';
            $type_path = $type_paths[$asset->product?->product_type_id] ?? null;

            return [
                'key' => 'asset-' . $asset->id,
                'label' => ContractLabels::asset($asset),
                'name' => self::modelName($asset->product) ?? $type_path ?? 'Machine zonder model',
                'detail' => self::detail($type_path, $asset->serial_number ? 'serienummer ' . $asset->serial_number : null, $address),
                'address_key' => $asset->location_id ? 'location-' . $asset->location_id : 'customer',
                'address_label' => $address,
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
        $type_paths = ProductType::pathMap();

        $machines = [];

        foreach (array_values($sample) as $line_index => $line) {
            $product = isset($line['product_id']) ? $products->get((int) $line['product_id']) : null;
            $type_id = $product?->product_type_id ?? ($line['product_type_id'] ?? null);
            $type_path = $type_paths[(int) $type_id] ?? null;
            $address = max(1, (int) ($line['address'] ?? 1));
            $facts = $product ? self::productFacts($product) : self::productFacts(null, $type_id);
            $name = self::modelName($product) ?? ($type_path ? Str::afterLast($type_path, ' → ') : 'Machine zonder type');

            for ($i = 1; $i <= (int) ($line['quantity'] ?? 0); $i++) {
                $machines[] = [
                    'key' => 'sample-' . $line_index . '-' . $i,
                    'label' => $name,
                    'name' => $name,
                    'detail' => self::detail($type_path, 'voorbeeldklant, adres ' . $address),
                    'address_key' => 'address-' . $address,
                    'address_label' => 'Adres ' . $address,
                    'visits_per_year' => self::timesPerYear($frequency, $frequency_days),
                    ...$facts,
                ];
            }
        }

        return $machines;
    }

    /** Brand and model, as a person would name the machine: "Daikin FTXM25". */
    private static function modelName(?Product $product): ?string
    {
        $name = trim(($product?->brand?->name ?? '') . ' ' . ($product?->model ?? ''));

        return $name === '' ? null : $name;
    }

    /** What tells one machine from the next in a list: its type, serial number and address. */
    private static function detail(?string ...$parts): string
    {
        return implode(' · ', array_filter($parts));
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
