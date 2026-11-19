<?php

namespace App\Domain\Signals\Products;

use App\Models\ProductAttribute;

class ProductAttributeValueAdded extends ProductAttributeValueSignal
{
    public function __construct(ProductAttribute $attribute, string $label)
    {
        parent::__construct($attribute, null, $label);
    }

    public static function key(): string
    {
        return 'productattribute.value_added';
    }

    public static function label(): string
    {
        return 'Kenmerkwaarde toegevoegd';
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function activityDescription(): ?string
    {
        return 'Waarde \'' . $this->new_label . '\' toegevoegd aan ' . $this->attribute->name;
    }
}
