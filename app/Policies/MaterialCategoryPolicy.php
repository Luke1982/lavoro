<?php

namespace App\Policies;

use App\Models\MaterialCategory;
use App\Models\User;

class MaterialCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('materialcategory.read');
    }

    public function view(User $user, MaterialCategory $material_category): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('materialcategory.create');
    }

    /** Asked of the class: every one of them takes the same right. */
    public function update(User $user): bool
    {
        return $user->hasPermission('materialcategory.update');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('materialcategory.delete');
    }
}
