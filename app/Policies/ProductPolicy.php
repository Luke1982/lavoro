<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Reading products at all: the whole catalogue, or only the products of the
     * machines one may see. Which ones is Product::visibleTo's business; whoever
     * sees a machine sees its product without this.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['product.read', 'product.read.relevant.serviceorder']);
    }

    /** The product list: everyone it is in the menu for. */
    public function list(User $user): bool
    {
        return $user->hasPermission('product.read');
    }

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
        return Product::visibleTo($user)->whereKey($product->getKey())->exists();
    }

    /** Asked of the class: the right is the same for every product. */
    public function update(User $user): bool
    {
        return $user->hasPermission('product.update');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('product.delete');
    }
}
