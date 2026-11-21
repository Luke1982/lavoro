<?php

namespace App\Domain\Signals\Products;

use App\Models\Product;
use App\Models\Productable;

class ProductComponentRemoved extends ProductComponentSignal
{
    /** @param  ?Product  $deleted  the product whose deletion took the line off: the part, or the product it was on */
    public function __construct(Product $product, Product $component, Productable $row, public ?Product $deleted = null)
    {
        parent::__construct($product, $component, $row);
    }

    public static function key(): string
    {
        return 'product.component_removed';
    }

    public static function label(): string
    {
        return 'Onderdeel verwijderd';
    }

    public function activityDescription(): ?string
    {
        return 'Onderdeel ' . $this->componentName() . ' verwijderd van ' . $this->productName()
            . ($this->deleted ? " omdat product '" . self::nameOf($this->deleted) . "' is verwijderd" : '')
            . ' (was ' . $this->terms() . ')';
    }

    public function changes(): array
    {
        return [$this->componentRow(false)];
    }
}
