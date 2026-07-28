<?php

namespace App\Domain\Tools;

use App\Models\User;

/**
 * A coarse grouping of users that share one tool list.
 *
 * Tool definitions are the first thing in the prompt, so a per-user tool list
 * means no two people ever share a cached prefix and every turn is billed from
 * cold. Grouping users into a handful of profiles restores that sharing.
 *
 * A profile decides only what the assistant is told exists. What a user may
 * actually do is decided per call, by policies, in Tool::authorize().
 */
enum ToolProfile: string
{
    case technician = 'technician';
    case planner = 'planner';
    case administrator = 'administrator';

    public static function forUser(User $user): self
    {
        if ($user->isAdmin()) {
            return self::administrator;
        }

        return $user->hasPermission('event.see_all') || $user->hasPermission('event.create_others')
            ? self::planner
            : self::technician;
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
