<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\QuoteUnit;
use App\Models\User;

/** Part of the quote settings, read on that page only. */
class QuoteUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manageSettings', Quote::class);
    }

    public function view(User $user, QuoteUnit $unit): bool
    {
        return $this->viewAny($user);
    }
}
