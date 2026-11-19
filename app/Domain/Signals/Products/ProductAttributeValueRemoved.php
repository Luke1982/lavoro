<?php

namespace App\Domain\Signals\Products;

use App\Models\Product;
use App\Models\ProductAttribute;

class ProductAttributeValueRemoved extends ProductAttributeValueSignal
{
    /** @param  array<int, Product>  $products */
    public function __construct(ProductAttribute $attribute, string $label, array $products)
    {
        parent::__construct($attribute, $label, null, $products);
    }

    public static function key(): string
    {
        return 'productattribute.value_removed';
    }

    public static function label(): string
    {
        return 'Kenmerkwaarde verwijderd';
    }

    public function activityDescription(): ?string
    {
        return 'Waarde \'' . $this->old_label . '\' van ' . $this->attribute->name . ' verwijderd'
            . ($this->products === [] ? '' : ', bij ' . count($this->products) . ' '
                . (count($this->products) === 1 ? 'product' : 'producten'));
    }
}
