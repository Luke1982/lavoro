<?php

namespace App\Actions\Materials;

use App\Models\Material;

/**
 * Adds a material, with what a material starts with when nobody says otherwise:
 * no price, no stock, active and not divisible. The form and the spreadsheet
 * import both end here, so an imported material is made exactly like a typed one.
 */
class CreateMaterialAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): Material
    {
        return Material::create(array_merge([
            'description' => null,
            'code' => null,
            'vendor_code' => null,
            'price' => 0,
            'cost_price' => null,
            'divisable' => false,
            'is_active' => true,
            'is_service' => false,
            'stock' => 0,
            'min_stock' => 0,
            'max_stock' => 0,
        ], $attributes));
    }
}
