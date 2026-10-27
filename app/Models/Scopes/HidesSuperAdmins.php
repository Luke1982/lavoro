<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Hides MajorLabel's accounts from the customer.
 *
 * As a global scope and not per list: users are fetched in dozens of places --
 * the users screen, roles, notifications, the search bar, the planner -- and
 * one forgotten place is enough to show our account after all, with a delete
 * button next to it.
 *
 * Two exceptions, both needed:
 *
 * - With nobody logged in the scope does nothing. Otherwise logging in cannot
 *   find its own user any more and a super admin never gets in again. Commands
 *   and workers fall under this too.
 * - A super admin does see them, otherwise they cannot see themselves.
 *
 * Auth::hasUser() and not Auth::user(): the latter would want to fetch the
 * user, which calls this scope again.
 */
class HidesSuperAdmins implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!Auth::hasUser()) {
            return;
        }

        $user = Auth::user();

        if ($user instanceof $model && $user->isSuperAdmin()) {
            return;
        }

        $builder->withoutSuperAdmins();
    }
}
