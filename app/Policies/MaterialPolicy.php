<?php

namespace App\Policies;

use App\Models\User;

class MaterialPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('material.create');
    }

    /** The cost price. Asked of the class: the answer is the same for every material. */
    public function seeFinancials(User $user): bool
    {
        return $user->hasPermission('material.see_financials');
    }
}
