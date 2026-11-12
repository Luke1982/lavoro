<?php

namespace App\Http\Requests;

use App\Models\ProductType;

/**
 * Making a product type asks for producttype.create. The rules are shared with
 * updating one.
 */
class ProductTypeStoreRequest extends ProductTypeStoreUpdateRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ProductType::class) ?? false;
    }
}
