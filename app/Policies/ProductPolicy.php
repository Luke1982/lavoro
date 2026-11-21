<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /** The product list: everyone it is in the menu for. */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('product.read');
    }

    /**
     * Determine if the user can view the product.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('product.create');
    }

    /**
     * Reading a spreadsheet into the products. It makes new products and
     * changes existing ones, so it takes both rights.
     */
    public function import(User $user): bool
    {
        return $user->hasPermission('product.create') && $user->hasPermission('product.update');
    }

    /** The purchase and retail price. Asked of the class: the answer is the same for every product. */
    public function viewPrices(User $user): bool
    {
        return $user->hasPermission('product.view_prices');
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

    public function delete(User $user): bool
    {
        return $user->hasPermission('product.delete');
    }
}
