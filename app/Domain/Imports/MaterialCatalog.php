<?php

namespace App\Domain\Imports;

use App\Actions\Materials\CreateMaterialAction;
use App\Enums\CatalogImportKind;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MaterialCatalog extends CatalogTarget
{
    public function kind(): CatalogImportKind
    {
        return CatalogImportKind::materials;
    }

    protected function allFields(): array
    {
        return [
            'name' => ['label' => 'Naam', 'type' => 'text', 'attribute' => 'name', 'required' => true, 'updates' => false],
            'description' => ['label' => 'Omschrijving', 'type' => 'longtext', 'attribute' => 'description'],
            'code' => ['label' => 'Code', 'type' => 'text', 'attribute' => 'code'],
            'vendor_code' => ['label' => 'Leverancierscode', 'type' => 'text', 'attribute' => 'vendor_code'],
            'article_number' => ['label' => 'Artikelnummer bij de leverancier', 'type' => 'text', 'supplier' => true],
            'cost_price' => [
                'label' => 'Kostprijs',
                'type' => 'price',
                'attribute' => 'cost_price',
                'financial' => true,
            ],
            'price' => ['label' => 'Verkoopprijs', 'type' => 'price', 'attribute' => 'price'],
            'category' => [
                'label' => 'Categorie',
                'type' => 'lookup',
                'attribute' => 'material_category_id',
                'lookup' => MaterialCategory::class,
                'required' => true,
                'updates' => false,
            ],
            'unit' => [
                'label' => 'Eenheid',
                'type' => 'lookup',
                'attribute' => 'material_usage_unit_id',
                'lookup' => MaterialUsageUnit::class,
                'required' => true,
                'updates' => false,
            ],
        ];
    }

    protected function canSeePrices(User $user): bool
    {
        return $user->can('seeFinancials', Material::class);
    }

    public function primaryField(): string
    {
        return 'name';
    }

    public function costField(): string
    {
        return 'cost_price';
    }

    public function saleField(): string
    {
        return 'price';
    }

    public function noun(): string
    {
        return 'materiaal';
    }

    public function plural(): string
    {
        return 'materialen';
    }

    public function create(array $attributes): Model
    {
        return app(CreateMaterialAction::class)->execute($attributes);
    }

    public function label(Model $record): string
    {
        return (string) $record->getAttribute('name');
    }

    protected function matchKeys(?Supplier $supplier): array
    {
        return array_filter([
            'article_number' => $supplier ? 'het artikelnummer bij ' . $supplier->name : null,
            'code' => 'de code',
            'vendor_code' => 'de leverancierscode',
            'name' => 'de naam',
        ]);
    }

    protected function keyValue(string $key, array $values, array $settings, CatalogLookups $lookups): ?string
    {
        return self::textKey($values, $key);
    }

    protected function recordsByKey(string $key, array $values, ?Supplier $supplier): array
    {
        return $key === 'article_number'
            ? $this->bySupplierArticle($supplier, $values)
            : $this->byColumn($key, $values);
    }
}
