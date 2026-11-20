<?php

namespace App\Policies;

use App\Models\MaintenanceContract;
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

    /**
     * Working a draft out, on made-up machines or on a real contract. The second
     * shows that contract's money, so it takes the right to see contract prices.
     */
    public function tryOn(User $user, ?MaintenanceContract $contract = null): bool
    {
        if (!$this->viewAny($user)) {
            return false;
        }

        return $contract === null
            || ($user->can('view', $contract) && $user->can('seeFinancials', MaintenanceContract::class));
    }

    /** Moving contracts to the newest version: a new price on each of them. */
    public function upgradeContracts(User $user, PriceFormula $priceFormula): bool
    {
        return $this->update($user, $priceFormula)
            && $user->can('update', new MaintenanceContract)
            && $user->can('seeFinancials', MaintenanceContract::class);
    }
}
