<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class InitializeTenancyForApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        \Illuminate\Support\Facades\Log::info('API MW ENTER');
        $tenant_id = $request->hasSession() ? $request->session()->get("tenant_id") : null;
        $tenant_id = $tenant_id ?: $request->cookie('tenant_id');

        if (! $tenant_id) {
            return response()->json(['message' => 'Tenant kon niet worden bepaald.'], 400);
        }

        $tenant = Tenant::on('central')->find($tenant_id);

        if (! $tenant) {
            return response()->json(['message' => 'Onbekende tenant.'], 400);
        }

        $initialized_here = false;

        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant);
            $initialized_here = true;
        }

        \Illuminate\Support\Facades\Log::info(sprintf(
            'API MW: hasSession=%s tenant_id=%s initialized=%s default=%s',
            var_export($request->hasSession(), true), var_export($tenant_id, true),
            var_export(tenancy()->initialized, true), config('database.default')));

        $response = $next($request);

        if ($initialized_here && tenancy()->initialized) {
            tenancy()->end();
        }

        return $response;
    }
}
