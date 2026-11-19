<?php

namespace App\Actions\Quotes;

use App\Domain\Signals\Quotes\ProductMaterialDefaultRemoved;
use App\Domain\Signals\Signals;
use App\Models\ProductMaterialDefault;

class RemoveProductMaterialDefaultAction
{
    public function execute(ProductMaterialDefault $default): void
    {
        $default->loadMissing(['product.brand', 'material']);
        $default->delete();

        Signals::dispatch(new ProductMaterialDefaultRemoved($default->product, $default->material->name));
    }
}
