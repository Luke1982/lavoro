<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\QuoteTemplate;
use App\Models\User;

/** Part of the quote settings, read on that page only. */
class QuoteTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manageSettings', Quote::class);
    }

    public function view(User $user, QuoteTemplate $template): bool
    {
        return $this->viewAny($user);
    }
}
