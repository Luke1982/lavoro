<?php

namespace App\Support;

use App\Models\User;

final class AccessTokens
{
    /**
     * Bewust niet $user->tokens()->delete(): dat is een query-builder-delete,
     * die geen model-events afvuurt, waardoor de centrale rijen blijven staan
     * terwijl de tokens weg zijn.
     */
    public static function revokeAll(User $user): void
    {
        $user->tokens->each->delete();
    }
}
