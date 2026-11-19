<?php

namespace App\Domain\Signals\Products;

use App\Models\Product;
use App\Models\ProductAttribute;

class ProductAttributeValueRenamed extends ProductAttributeValueSignal
{
    /** @param  array<int, Product>  $products */
    public function __construct(ProductAttribute $attribute, string $old_label, string $new_label, array $products)
    {
        parent::__construct($attribute, $old_label, $new_label, $products);
    }

    public static function key(): string
    {
        return 'productattribute.value_renamed';
    }

    public static function label(): string
    {
        return 'Kenmerkwaarde gewijzigd';
    }

    public function activityDescription(): ?string
    {
        return 'Waarde van ' . $this->attribute->name . ' gewijzigd van \'' . $this->old_label
            . '\' naar \'' . $this->new_label . '\'';
    }
}
