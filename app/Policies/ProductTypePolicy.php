<?php

namespace App\Policies;

use App\Models\ProductType;
use App\Models\User;

class ProductTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('producttype.read');
    }

    public function view(User $user, ProductType $product_type): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('producttype.create');
    }

    /** Asked of the class: every one of them takes the same right. */
    public function update(User $user): bool
    {
        return $user->hasPermission('producttype.update');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('producttype.delete');
    }
}
