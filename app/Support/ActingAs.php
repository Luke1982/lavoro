<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Runs work in a queue worker as the person who asked for it. A signal reads its
 * actor from the session, which a worker does not have, so without this the
 * history of everything the work changes names the system instead of them.
 *
 * Whoever was signed in before is put back afterwards, also when the work fails:
 * a worker runs job after job, and the next one must not be credited to them,
 * and work run straight from a request (a sync queue, a test) must not sign out
 * the person who made it.
 */
final class ActingAs
{
    /** Without a person the work runs as the system, as it would have anyway. */
    public static function run(?User $user, Closure $work): mixed
    {
        if ($user === null) {
            return $work();
        }

        $before = Auth::user();
        Auth::setUser($user);

        try {
            return $work();
        } finally {
            if ($before === null) {
                Auth::forgetUser();
            } else {
                Auth::setUser($before);
            }
        }
    }
}
