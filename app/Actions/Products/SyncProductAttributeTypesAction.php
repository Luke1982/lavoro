<?php

namespace App\Actions\Products;

use App\Domain\Signals\Products\ProductAttributeProductTypesChanged;
use App\Domain\Signals\Signals;
use App\Models\ProductAttribute;
use App\Models\ProductType;

/** The product types a kenmerk applies to: the one way they change. */
class SyncProductAttributeTypesAction
{
    /** @param  array<int, int|string>  $type_ids */
    public function handle(ProductAttribute $attribute, array $type_ids): void
    {
        $before = $this->typeIds($attribute);
        $result = $attribute->productTypes()->sync(array_map('intval', $type_ids));
        $attribute->unsetRelation('productTypes');

        if ($result['attached'] === [] && $result['detached'] === []) {
            return;
        }

        $paths = ProductType::pathMap();
        $after = array_diff([...$before, ...$result['attached']], $result['detached']);

        Signals::dispatch(new ProductAttributeProductTypesChanged(
            $attribute,
            array_intersect_key($paths, array_flip($before)),
            array_intersect_key($paths, array_flip($after)),
        ));
    }

    public function attach(ProductAttribute $attribute, int $type_id): void
    {
        $this->handle($attribute, [...$this->typeIds($attribute), $type_id]);
    }

    public function detach(ProductAttribute $attribute, int $type_id): void
    {
        $this->handle($attribute, array_diff($this->typeIds($attribute), [$type_id]));
    }

    /** @return array<int, int> */
    private function typeIds(ProductAttribute $attribute): array
    {
        return $attribute->productTypes()->pluck('product_types.id')->map(fn ($id) => (int) $id)->all();
    }
}
