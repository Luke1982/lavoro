<?php

namespace App\Domain\Signals;

use App\Models\User;

/**
 * A person as the trail names them. Read past every scope: a user who has since
 * left is still who did it, and the scope that hides MajorLabel's accounts from the
 * customer would otherwise turn them into a bare id. Those accounts are named the
 * way the trail credits their work: MajorLabel, never the person.
 */
final class PersonName
{
    public static function of(int|string|null $user_id): ?string
    {
        if ($user_id === null || $user_id === '') {
            return null;
        }

        $user = User::withoutGlobalScopes()->find($user_id);

        if ($user === null) {
            return (string) $user_id;
        }

        return $user->isSuperAdmin() ? BaseSignal::MAJORLABEL : $user->name;
    }
}
