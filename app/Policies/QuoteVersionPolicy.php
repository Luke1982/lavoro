<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\QuoteVersion;
use App\Models\User;

/** Part of a quote: read by whoever may read the quote it belongs to. */
class QuoteVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Quote::class);
    }

    public function view(User $user, QuoteVersion $version): bool
    {
        return $version->quote !== null && $user->can('view', $version->quote);
    }
}
