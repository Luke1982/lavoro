<?php

namespace App\Policies;

use App\Models\LedgerAccount;
use App\Models\User;

/** Ledger accounts are kept with the integrations, by whoever manages those. */
class LedgerAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('integration.manage');
    }

    public function view(User $user, LedgerAccount $ledger_account): bool
    {
        return $this->viewAny($user);
    }
}
