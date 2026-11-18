<?php

namespace App\Enums;

use App\Domain\Imports\CatalogTarget;
use App\Domain\Imports\MaterialCatalog;
use App\Domain\Imports\ProductCatalog;
use App\Models\Material;
use App\Models\Product;

/**
 * What a spreadsheet import fills: the materials or the products. The value is
 * the path segment of the import route and is stored on saved mappings, so a
 * mapping made for materials is never offered for products.
 */
enum CatalogImportKind: string
{
    case materials = 'materials';
    case products = 'products';

    public function target(): CatalogTarget
    {
        return match ($this) {
            self::materials => new MaterialCatalog,
            self::products => new ProductCatalog,
        };
    }

    /** @return class-string */
    public function modelClass(): string
    {
        return match ($this) {
            self::materials => Material::class,
            self::products => Product::class,
        };
    }

    public function indexPath(): string
    {
        return '/' . $this->value;
    }

    /** What the import screen calls the records and where it links back to. */
    public function toPageArray(): array
    {
        $target = $this->target();

        return [
            'value' => $this->value,
            'noun' => $target->noun(),
            'plural' => $target->plural(),
            'path' => $this->indexPath(),
        ];
    }
}
