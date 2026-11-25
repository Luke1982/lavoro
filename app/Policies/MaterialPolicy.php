<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\User;

class MaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('material.read');
    }

    public function view(User $user, Material $material): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('material.create');
    }

    /** Asked of the class: the right is the same for every material. */
    public function update(User $user): bool
    {
        return $user->hasPermission('material.update');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('material.delete');
    }

    /**
     * Reading a spreadsheet into the materials. It makes new materials and
     * changes existing ones, so it takes both rights.
     */
    public function import(User $user): bool
    {
        return $user->hasPermission('material.create') && $user->hasPermission('material.update');
    }

    /** The cost price. Asked of the class: the answer is the same for every material. */
    public function seeFinancials(User $user): bool
    {
        return $user->hasPermission('material.see_financials');
    }
}
