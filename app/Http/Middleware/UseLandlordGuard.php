<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sets the default guard to landlord for the admin panel.
 *
 * Not merely tidy but necessary: the database session driver writes user_id
 * along and fetches it with auth()->guard()->id() -- the default guard, that
 * is. On a route without a tenant that web guard looks for the user in the
 * central database, where there is no users table, and that is a 500 while
 * writing the session instead of something while reading it.
 */
class UseLandlordGuard
{
    public function handle(Request $request, Closure $next): mixed
    {
        Auth::shouldUse('landlord');

        return $next($request);
    }
}
