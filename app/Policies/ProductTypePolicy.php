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
}
