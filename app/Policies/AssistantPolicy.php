<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may talk to the assistant.
 *
 * Explicit only. Every other policy here lets an admin through on the strength
 * of being an admin; this one does not, because the assistant is being trialled
 * by a named handful and "everyone who administers the system" is not that
 * group — and it grows on its own as people are made admins for other reasons.
 */
class AssistantPolicy
{
    public function use(User $user): bool
    {
        return $user->hasExplicitPermission('assistant.use');
    }
}
