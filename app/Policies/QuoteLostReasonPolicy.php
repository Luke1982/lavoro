<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\QuoteLostReason;
use App\Models\User;

/** Part of the quote settings, read on that page only. */
class QuoteLostReasonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manageSettings', Quote::class);
    }

    public function view(User $user, QuoteLostReason $reason): bool
    {
        return $this->viewAny($user);
    }
}
