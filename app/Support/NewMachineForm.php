<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductableService;
use Illuminate\Support\Collection;

/**
 * What every page that opens the new-machine drawer hands it: the whole catalogue while it
 * is small enough to send along, search-as-you-type once it is not, and the parts each
 * bundle asks for.
 */
final class NewMachineForm
{
    private const PRODUCT_AJAX_THRESHOLD = 50;

    /**
     * Nothing for someone who may not add machines: the drawer stays shut for them, and the
     * catalogue would only weigh down the page.
     *
     * @return array{allProducts?: Collection<int, array<string, mixed>>, productsUseAjax?: bool, bundlePartsByProduct?: array<int, array<int, array<string, mixed>>>}
     */
    public static function propsFor(?User $user): array
    {
        if (!$user?->can('create', Asset::class)) {
            return [];
        }

        return [
            ...self::productPickerProps(),
            'bundlePartsByProduct' => ProductableService::bundlePartsMap(),
        ];
    }

    /**
     * The product picker on its own, for a page that changes the product of a machine rather
     * than making one.
     *
     * @return array{allProducts: Collection<int, array<string, mixed>>, productsUseAjax: bool}
     */
    public static function productPickerProps(): array
    {
        $products_use_ajax = Product::count() > self::PRODUCT_AJAX_THRESHOLD;

        return [
            'allProducts' => $products_use_ajax ? collect() : self::productOptions(),
            'productsUseAjax' => $products_use_ajax,
        ];
    }

    /**
     * Every product as the machine forms offer it, ordered by type. Bundle and registable
     * ride along because they decide whether the form asks for a serienummer at all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function productOptions(): Collection
    {
        return Product::with(['brand', 'productType'])
            ->join('product_types', 'products.product_type_id', '=', 'product_types.id')
            ->orderBy('product_types.name', 'ASC')
            ->select('products.*')
            ->get()
            ->map->toMachineOption();
    }
}
