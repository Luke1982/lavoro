<?php

namespace App\Policies;

use App\Models\User;

class BrandPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('brand.create');
    }
}
