<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

/**
 * Quotes are a paid module. Every right here asks first whether this customer
 * has it, and not only the routes: the assistant, the activity search and any
 * later path come in through the policy, not through the route middleware.
 */
class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return self::licensed() && $user->hasPermission('quote.read');
    }

    public function view(User $user, Quote $quote): bool
    {
        return self::licensed() && $user->hasPermission('quote.read');
    }

    public function create(User $user): bool
    {
        return self::licensed() && $user->hasPermission('quote.create');
    }

    public function update(User $user, Quote $quote): bool
    {
        return self::licensed() && $user->hasPermission('quote.update');
    }

    public function delete(User $user, Quote $quote): bool
    {
        return self::licensed() && $user->hasPermission('quote.delete');
    }

    /** Purchase prices and margins. Asked of the class: the answer is the same for every quote. */
    public function seeMargins(User $user): bool
    {
        return self::licensed() && $user->hasPermission('quote.see_margins');
    }

    /** Which materials come along with which product. */
    public function manageDefaults(User $user): bool
    {
        return self::licensed() && $user->hasPermission('quote.manage_defaults');
    }

    public static function licensed(): bool
    {
        return tenancy()->initialized && tenancy()->tenant->hasModule('quotes');
    }
}
