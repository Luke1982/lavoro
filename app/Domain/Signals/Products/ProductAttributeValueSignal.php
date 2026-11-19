<?php

namespace App\Domain\Signals\Products;

use App\Domain\Signals\BaseSignal;
use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Model;

/**
 * One value in the list of a kenmerk changed. The fact is about the kenmerk;
 * a rename or a removal also changes what every product holding that value
 * says, so it is on their timelines as well.
 */
abstract class ProductAttributeValueSignal extends BaseSignal
{
    /** @param  array<int, Product>  $products */
    public function __construct(
        public ProductAttribute $attribute,
        public ?string $old_label,
        public ?string $new_label,
        public array $products = [],
    ) {
        parent::__construct();
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function subject(): Model
    {
        return $this->attribute;
    }

    public function activityContext(): array
    {
        return $this->products;
    }

    public function changes(): array
    {
        return [[
            'field' => ProductAttributesChanged::field($this->attribute->id),
            'label' => $this->attribute->name,
            'old_value' => $this->old_label,
            'new_value' => $this->new_label,
            'old_label' => $this->old_label,
            'new_label' => $this->new_label,
        ]];
    }
}
