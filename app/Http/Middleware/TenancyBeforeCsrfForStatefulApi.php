<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

/**
 * Sanctum's CSRF check for the SPA's API requests, with the tenant set first.
 *
 * Sanctum checks the CSRF token before TenancyForStatefulApi gets its turn.
 * When the session has expired the token no longer matches, and the request
 * is refused -- rightly, with a 419. But the refused session is still saved,
 * and saving asks the guard who is logged in. With only the remember-me
 * cookie left, the guard went looking for that user while no tenant was set
 * yet, in the central database, which has no users table: every /api POST
 * after an expired session became a 500. The location service of the Android
 * app hit it every morning, because nothing kept its session alive overnight.
 *
 * Sits in sanctum.middleware.validate_csrf_token, the last hook before the
 * check. The session has been started by then, so the tenant in the session
 * still comes before the one in the cookie.
 */
class TenancyBeforeCsrfForStatefulApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        TenancyForStatefulApi::initialize($request);

        return app(ValidateCsrfToken::class)->handle($request, $next);
    }
}
