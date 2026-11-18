<?php

namespace App\Domain\Imports;

use App\Enums\CatalogImportKind;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProductCatalog extends CatalogTarget
{
    public function kind(): CatalogImportKind
    {
        return CatalogImportKind::products;
    }

    protected function allFields(): array
    {
        return [
            'model' => ['label' => 'Model', 'type' => 'text', 'attribute' => 'model', 'required' => true, 'updates' => false],
            'brand' => [
                'label' => 'Merk',
                'type' => 'lookup',
                'attribute' => 'brand_id',
                'lookup' => Brand::class,
                'required' => true,
                'updates' => false,
            ],
            'product_type' => [
                'label' => 'Producttype',
                'type' => 'lookup',
                'attribute' => 'product_type_id',
                'lookup' => ProductType::class,
                'required' => true,
                'updates' => false,
            ],
            'description' => ['label' => 'Omschrijving', 'type' => 'longtext', 'attribute' => 'description'],
            'part_no' => ['label' => 'Artikelnummer', 'type' => 'text', 'attribute' => 'part_no'],
            'article_number' => ['label' => 'Artikelnummer bij de leverancier', 'type' => 'text', 'supplier' => true],
            'purchase_price' => [
                'label' => 'Inkoopprijs',
                'type' => 'price',
                'attribute' => 'purchase_price',
                'financial' => true,
            ],
            'retail_price' => [
                'label' => 'Verkoopprijs',
                'type' => 'price',
                'attribute' => 'retail_price',
                'financial' => true,
            ],
        ];
    }

    protected function canSeePrices(User $user): bool
    {
        return $user->can('viewPrices', Product::class);
    }

    public function primaryField(): string
    {
        return 'model';
    }

    public function costField(): string
    {
        return 'purchase_price';
    }

    public function saleField(): string
    {
        return 'retail_price';
    }

    public function noun(): string
    {
        return 'product';
    }

    public function plural(): string
    {
        return 'producten';
    }

    public function create(array $attributes): Model
    {
        return Product::create($attributes);
    }

    public function label(Model $record): string
    {
        return $record instanceof Product
            ? (string) ($record->display_name ?? $record->model)
            : (string) $record->getAttribute('model');
    }

    protected function matchKeys(?Supplier $supplier): array
    {
        return array_filter([
            'article_number' => $supplier ? 'het artikelnummer bij ' . $supplier->name : null,
            'part_no' => 'het artikelnummer',
            'model' => 'merk en model samen',
        ]);
    }

    protected function matchKeyLabel(string $key): string
    {
        return $key === 'model' ? 'Merk en model' : parent::matchKeyLabel($key);
    }

    /**
     * A model name alone is not unique -- two brands both sell an "X100" -- so a
     * product is recognised by the brand and the model together. A brand that
     * does not exist yet still gives a key, so two rows of the same new product
     * in one file are seen as one.
     */
    protected function keyValue(string $key, array $values, array $settings, CatalogLookups $lookups): ?string
    {
        if ($key !== 'model') {
            return self::textKey($values, $key);
        }

        $model = self::textKey($values, 'model');

        if ($model === null) {
            return null;
        }

        $brand_name = $values['brand'] ?? null;
        $brand_id = $brand_name !== null ? $lookups->idFor('brand', $brand_name) : ($settings['fixed']['brand'] ?? null);

        if ($brand_id === null) {
            return $brand_name === null ? null : 'new:' . CatalogLookups::key($brand_name) . '|' . $model;
        }

        return $brand_id . '|' . $model;
    }

    protected function recordsByKey(string $key, array $values, ?Supplier $supplier): array
    {
        if ($key === 'article_number') {
            return $this->bySupplierArticle($supplier, $values);
        }

        if ($key !== 'model') {
            return $this->byColumn($key, $values);
        }

        $models = [];

        foreach ($values as $value) {
            [$brand_id, $model] = explode('|', $value, 2);

            if (ctype_digit($brand_id)) {
                $models[$model] = true;
            }
        }

        $index = [];

        foreach (array_chunk(array_keys($models), 1000) as $chunk) {
            Product::query()
                ->whereIn('model', $chunk)
                ->get(['id', 'brand_id', 'model'])
                ->each(function (Product $product) use (&$index) {
                    $index[$product->brand_id . '|' . CatalogLookups::key((string) $product->model)][] = $product->id;
                });
        }

        return $index;
    }
}
