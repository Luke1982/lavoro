<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;

/** Part of a quote: read by whoever may read the quote it belongs to. */
class QuoteLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Quote::class);
    }

    public function view(User $user, QuoteLine $line): bool
    {
        return $line->quote !== null && $user->can('view', $line->quote);
    }
}
