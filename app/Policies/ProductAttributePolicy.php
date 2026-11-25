<?php

namespace App\Policies;

use App\Models\ProductAttribute;
use App\Models\User;

class ProductAttributePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('productattribute.read');
    }

    public function view(User $user, ProductAttribute $product_attribute): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('productattribute.create');
    }

    public function update(User $user, ProductAttribute $productAttribute): bool
    {
        return $user->hasPermission('productattribute.update');
    }

    public function delete(User $user, ProductAttribute $productAttribute): bool
    {
        return $user->hasPermission('productattribute.delete');
    }
}
