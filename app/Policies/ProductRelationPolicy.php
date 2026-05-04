<?php

namespace App\Policies;

use App\Models\ProductRelation;
use App\Models\User;

class ProductRelationPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('productrelation.create');
    }

    public function update(User $user, ProductRelation $productRelation): bool
    {
        return $user->hasPermission('productrelation.update');
    }

    public function delete(User $user, ProductRelation $productRelation): bool
    {
        return $user->hasPermission('productrelation.delete');
    }
}
