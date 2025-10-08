<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Product;

class ProductPolicy
{
    /**
     * Determine if the user can view the product.
     */
    public function view(User $user, Product $product): bool
    {
        if ($user->hasPermission('product.read')) {
            return true;
        }
        if ($user->hasPermission('product.read.relevant.serviceorder')) {
            return in_array($product->id, $user->relevantProductIds());
        }
        return false;
    }
}
