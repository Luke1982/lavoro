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

    /**
     * Only a part of a bundle is changed here. A material default is changed
     * through its own requests, under QuotePolicy::manageDefaults, and what a
     * werkbon taak chose goes with the taak.
     */
    public function update(User $user, Productable $productable): bool
    {
        return $productable->isComponent() && $user->hasPermission('productable.update');
    }

    public function delete(User $user, Productable $productable): bool
    {
        return $productable->isComponent() && $user->hasPermission('productable.delete');
    }
}
