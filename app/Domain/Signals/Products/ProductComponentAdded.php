<?php

namespace App\Domain\Signals\Products;

class ProductComponentAdded extends ProductComponentSignal
{
    public static function key(): string
    {
        return 'product.component_added';
    }

    public static function label(): string
    {
        return 'Onderdeel toegevoegd';
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function activityDescription(): ?string
    {
        return 'Onderdeel ' . $this->componentName() . ' toegevoegd aan ' . $this->productName()
            . ' (' . $this->terms() . ')';
    }

    public function changes(): array
    {
        return [$this->componentRow(true)];
    }
}
