<?php

namespace App\Actions\Products;

use App\Domain\Signals\Products\ProductAttributesChanged;
use App\Domain\Signals\Signals;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sets the kenmerken of products: the one way a product's values change, so
 * every product says on its timeline what it read before and after.
 *
 * A value is only set for a kenmerk the product's type has, because only those
 * are on its form. Clearing works whatever the type, so a value left from the
 * type a product had before can still be taken off.
 */
class SetProductAttributeValuesAction
{
    /** @param  array<int, int|string|null>  $values  kenmerk id → value id, null clears it */
    public function handle(Product $product, array $values): void
    {
        $this->handleMany(new Collection([$product]), $values);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<int, int|string|null>  $values  kenmerk id → value id, null clears it
     */
    public function handleMany(Collection $products, array $values): void
    {
        $values = array_map(fn ($value_id) => blank($value_id) ? null : (int) $value_id, $values);

        if ($values === [] || $products->isEmpty()) {
            return;
        }

        $products->loadMissing(['productType.productAttributes', 'productAttributeValueables.value']);
        $names = ProductAttribute::whereKey(array_keys($values))->pluck('name', 'id');
        $labels = ProductAttributeValue::whereKey(array_filter($values))->pluck('value', 'id');

        foreach ($products as $product) {
            $changes = $this->apply($product, $values, $names->all(), $labels->all());

            if ($changes !== []) {
                Signals::dispatch(new ProductAttributesChanged($product, $changes));
            }
        }
    }

    /**
     * @param  array<int, ?int>  $values
     * @param  array<int, string>  $names
     * @param  array<int, string>  $labels
     * @return array<int, array<string, ?string>>
     */
    private function apply(Product $product, array $values, array $names, array $labels): array
    {
        $allowed = $product->productType->productAttributes->modelKeys();
        $current = $product->productAttributeValueables->keyBy('product_attribute_id');
        $changes = [];

        foreach ($values as $attribute_id => $value_id) {
            $before = $current->get($attribute_id);

            if ($before?->product_attribute_value_id === $value_id
                || ($value_id !== null && !in_array($attribute_id, $allowed, true))) {
                continue;
            }

            $before?->delete();

            if ($value_id !== null) {
                $product->productAttributeValueables()->create([
                    'product_attribute_id' => $attribute_id,
                    'product_attribute_value_id' => $value_id,
                ]);
            }

            $changes[] = [
                'field' => ProductAttributesChanged::field($attribute_id),
                'label' => $names[$attribute_id],
                'old_value' => $before ? (string) $before->product_attribute_value_id : null,
                'new_value' => $value_id === null ? null : (string) $value_id,
                'old_label' => $before?->value->value,
                'new_label' => $value_id === null ? null : $labels[$value_id],
            ];
        }

        $product->unsetRelation('productAttributeValueables');

        return $changes;
    }
}
