<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/**
 * Zet de tenant voor API-verzoeken van de SPA.
 *
 * Dit draait binnen Sanctum's eigen pipeline, niet in de api-groep. Sanctum
 * start de sessie in een genest pipeline en roept daarna pas $next aan, dus
 * middleware in de api-groep komt te laat: auth:sanctum heeft de gebruiker dan
 * al opgezocht in de centrale database. Het laatste onderdeel van die pipeline
 * is instelbaar via sanctum.middleware.authenticate_session, en dat is het
 * eerste punt waarop de sessie er is en de gebruiker nog niet opgehaald.
 */
class TenancyForStatefulApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant_id = $request->hasSession() ? $request->session()->get('tenant_id') : null;
        $tenant_id = $tenant_id ?: $request->cookie('tenant_id');

        if ($tenant_id && ! tenancy()->initialized) {
            $tenant = Tenant::on('central')->find($tenant_id);

            if ($tenant) {
                tenancy()->initialize($tenant);
            }
        }

        return app(AuthenticateSession::class)->handle($request, $next);
    }
}
