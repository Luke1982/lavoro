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
}
