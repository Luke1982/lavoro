<?php

namespace App\Actions\Quotes;

use App\Domain\Signals\Quotes\ProductMaterialDefaultSet;
use App\Domain\Signals\Signals;
use App\Models\Product;
use App\Models\ProductMaterialDefault;
use App\Support\PendingChanges;

/**
 * Sets a material to come along with a product on quotes, or changes how: the
 * quantity per unit, or whether it is added, asked or suggested. A save that
 * changes nothing says nothing.
 */
class SaveProductMaterialDefaultAction
{
    /** @param array{material_id?: int, quantity?: float|int|string, prompt?: string} $data the validated fields */
    public function execute(Product $product, array $data, ?ProductMaterialDefault $default = null): ProductMaterialDefault
    {
        $default ??= new ProductMaterialDefault([
            'product_id' => $product->id,
            'material_id' => $data['material_id'],
            'position' => (int) $product->materialDefaults()->max('position') + 1,
        ]);
        $is_new = !$default->exists;

        $default->fill(array_intersect_key($data, array_flip(['quantity', 'prompt'])));
        $fields = PendingChanges::of($default, ['quantity', 'prompt']);

        if ($fields === []) {
            return $default;
        }

        $default->save();

        Signals::dispatch(new ProductMaterialDefaultSet($product->loadMissing('brand'), $default->load('material'), $is_new, $fields));

        return $default;
    }
}
