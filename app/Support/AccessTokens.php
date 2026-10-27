<?php

namespace App\Support;

use App\Models\User;

final class AccessTokens
{
    /**
     * Deliberately not $user->tokens()->delete(): that is a query builder
     * delete, which fires no model events, leaving the central rows behind
     * while the tokens are gone.
     */
    public static function revokeAll(User $user): void
    {
        $user->tokens->each->delete();
    }
}
