<?php

namespace App\Actions\Quotes;

use App\Domain\Signals\Quotes\ProductMaterialDefaultRemoved;
use App\Domain\Signals\Signals;
use App\Models\Productable;

class RemoveProductMaterialDefaultAction
{
    public function execute(Productable $default): void
    {
        $default->loadMissing(['product.brand', 'material']);
        $default->delete();

        Signals::dispatch(new ProductMaterialDefaultRemoved($default->product, $default->material->name));
    }
}
