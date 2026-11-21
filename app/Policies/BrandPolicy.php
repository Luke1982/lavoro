<?php

namespace App\Policies;

use App\Models\User;

class BrandPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('brand.create');
    }

    public function update(User $user): bool
    {
        return $user->hasPermission('brand.update');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('brand.delete');
    }
}
