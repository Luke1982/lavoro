<?php

namespace App\Http\Middleware;

use App\Models\Central\AccessTokenTenantLookup;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
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
 */
class TenancyForStatefulApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant_id = $request->hasSession() ? $request->session()->get('tenant_id') : null;
        $tenant_id = $tenant_id ?: $request->cookie('tenant_id');

        if (!$tenant_id && $bearer = $request->bearerToken()) {
            $plain = str_contains($bearer, '|') ? Str::after($bearer, '|') : $bearer;

            $tenant_id = AccessTokenTenantLookup::on('central')
                ->where('token_hash', hash('sha256', $plain))
                ->value('tenant_id');
        }

        if ($tenant_id && !tenancy()->initialized) {
            $tenant = Tenant::on('central')->find($tenant_id);

            if ($tenant) {
                tenancy()->initialize($tenant);
            }
        }

        return app(AuthenticateSession::class)->handle($request, $next);
    }
}
