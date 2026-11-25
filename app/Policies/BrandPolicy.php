<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('brand.read');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->viewAny($user);
    }

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
