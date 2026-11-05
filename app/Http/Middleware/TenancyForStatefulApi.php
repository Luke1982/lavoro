<?php

namespace App\Http\Middleware;

use App\Models\Central\AccessTokenTenantLookup;
use App\Models\Tenant;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/**
 * Sets the tenant for the SPA's API requests.
 *
 * This runs inside Sanctum's own pipeline, not in the api group. Sanctum starts
 * the session in a nested pipeline and only calls $next afterwards, so
 * middleware in the api group comes too late: auth:sanctum has looked the user
 * up in the central database by then. The last element of that pipeline is
 * configurable through sanctum.middleware.authenticate_session, and that is the
 * first point where the session exists and the user has not been fetched yet.
 *
 * The CSRF check before it needs the tenant as well, which is why
 * TenancyBeforeCsrfForStatefulApi calls initialize() first; here it then
 * finds the tenant already set.
 */
class TenancyForStatefulApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        static::initialize($request);

        return app(AuthenticateSession::class)->handle($request, $next);
    }

    /**
     * The tenant from the session, else from the long-lived cookie that
     * "remember me" leaves, else from a personal access token.
     *
     * Without a tenant nothing can be logged in: the users table lives in the
     * tenant database. Left alone, the guard would still look for one -- from
     * the session or from the remember-me cookie -- and ask the central
     * database for a users table that is not there: a 500 instead of a 401.
     * InitializeTenancyBySession does the same for the ordinary pages.
     */
    public static function initialize(Request $request): void
    {
        if (!tenancy()->initialized && $tenant = static::tenantFor($request)) {
            tenancy()->initialize($tenant);
        }

        if (!tenancy()->initialized) {
            static::forgetTheUser($request);
        }
    }

    private static function tenantFor(Request $request): ?Tenant
    {
        $tenant_id = $request->hasSession() ? $request->session()->get('tenant_id') : null;
        $tenant_id = $tenant_id ?: $request->cookie('tenant_id');

        if (!$tenant_id && $bearer = $request->bearerToken()) {
            $plain = str_contains($bearer, '|') ? Str::after($bearer, '|') : $bearer;

            $tenant_id = AccessTokenTenantLookup::on('central')
                ->where('token_hash', hash('sha256', $plain))
                ->value('tenant_id');
        }

        return $tenant_id ? Tenant::on('central')->find($tenant_id) : null;
    }

    private static function forgetTheUser(Request $request): void
    {
        $guard = Auth::guard();

        Auth::forgetUser();

        if (!$guard instanceof SessionGuard) {
            return;
        }

        if ($request->hasSession()) {
            $request->session()->forget($guard->getName());
        }

        $recaller = $guard->getRecallerName();

        $request->cookies->remove($recaller);
        cookie()->queue(cookie()->forget($recaller));
    }
}
