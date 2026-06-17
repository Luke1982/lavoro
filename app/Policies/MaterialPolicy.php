<?php

namespace App\Policies;

use App\Models\User;

class MaterialPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('material.create');
    }
}
