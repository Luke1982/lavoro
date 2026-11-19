<?php

namespace App\Policies;

use App\Models\PriceFormula;
use App\Models\User;

/**
 * Price formulas are what contracts cost, so every right on them is a right on
 * money. Moving contracts to a newer version changes their price, and comes with
 * the right to change the formula.
 */
class PriceFormulaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('priceformula.read');
    }

    public function view(User $user, PriceFormula $priceFormula): bool
    {
        return $user->hasPermission('priceformula.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('priceformula.create');
    }

    public function update(User $user, PriceFormula $priceFormula): bool
    {
        return $user->hasPermission('priceformula.update');
    }

    public function delete(User $user, PriceFormula $priceFormula): bool
    {
        return $user->hasPermission('priceformula.delete');
    }
}
