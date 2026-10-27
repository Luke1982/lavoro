<?php

namespace App\Http\Middleware;

use App\Models\Central\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantHasModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(
            Module::on('central')->where('key', $module)->exists(),
            500,
            "Onbekende module '{$module}'."
        );

        abort_unless(
            tenancy()->initialized && tenancy()->tenant->hasModule($module),
            403,
            'Deze functie is niet beschikbaar in uw abonnement.'
        );

        return $next($request);
    }
}
