<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Determine if the user can view the product.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('product.create');
    }

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
