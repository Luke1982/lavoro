<?php

namespace App\Policies;

use App\Models\User;

class ProductTypePolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('producttype.create');
    }
}
