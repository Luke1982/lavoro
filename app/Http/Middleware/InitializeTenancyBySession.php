<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

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

        $response = $next($request);

        if ($initialized_here && tenancy()->initialized) {
            tenancy()->end();
        }

        return $response;
    }
}
