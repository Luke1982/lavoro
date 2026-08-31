<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InitializeTenancyBySession
{
    public function handle(Request $request, Closure $next): mixed
    {
        $initialized_here = false;
        $tenant_id = $request->hasSession() ? $request->session()->get('tenant_id') : null;
        $tenant_id = $tenant_id ?: $request->cookie('tenant_id');

        if ($tenant_id && ! tenancy()->initialized) {
            $tenant = Tenant::on('central')->find($tenant_id);

            if ($tenant) {
                tenancy()->initialize($tenant);
                $initialized_here = true;

                if ($request->hasSession() && ! $request->session()->get('tenant_id')) {
                    $request->session()->put('tenant_id', $tenant->id);
                }
            } else {
                $request->hasSession() && $request->session()->forget('tenant_id');
                cookie()->queue(cookie()->forget('tenant_id'));
            }
        }

        /**
         * Zonder tenant kan er niets ingelogd zijn: de users-tabel staat in de
         * tenantdatabase. Laravel's remember-me herstelt de gebruiker pas na
         * deze middleware, dus zonder dit haalt hij er alsnog eentje terug en
         * vraagt Auth::user() de centrale database om een tabel die daar niet
         * staat -- een 500 in plaats van het inlogscherm.
         */
        if (! tenancy()->initialized) {
            Auth::forgetUser();

            $recaller = Auth::guard()->getRecallerName();

            $request->cookies->remove($recaller);
            cookie()->queue(cookie()->forget($recaller));
        }

        $response = $next($request);

        if ($initialized_here && tenancy()->initialized) {
            tenancy()->end();
        }

        return $response;
    }
}
