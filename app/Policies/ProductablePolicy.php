<?php

namespace App\Policies;

use App\Models\Productable;
use App\Models\User;

class ProductablePolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('productable.create');
    }

    public function update(User $user, Productable $productable): bool
    {
        return $user->hasPermission('productable.update');
    }

    public function delete(User $user, Productable $productable): bool
    {
        return $user->hasPermission('productable.delete');
    }
}
