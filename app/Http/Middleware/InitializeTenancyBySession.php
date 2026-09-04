<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InitializeTenancyBySession
{
    /**
     * Is de database van deze klant er nog?
     *
     * tenancy()->initialize() wisselt alleen de instellingen om en merkt niets;
     * de eerste vraag aan de database loopt dan stuk. Bij een klant die halverwege
     * is aangemaakt of opgeruimd betekende dat een 500 op elke pagina, ook op het
     * inlogscherm -- de hele installatie plat door één kapotte klant.
     *
     * Lukt het verbinden niet, dan doen we alsof er geen klant is: dan wordt de
     * sessie vergeten en kom je op het inlogscherm uit, waar je verder kunt.
     */
    private function reachable(Tenant $tenant): bool
    {
        try {
            tenancy()->initialize($tenant);

            DB::connection('tenant')->select('SELECT 1');

            return true;
        } catch (\Throwable $e) {
            Log::warning('De database van een klant is niet bereikbaar; sessie genegeerd', [
                'tenant' => $tenant->getTenantKey(),
                'fout' => $e->getMessage(),
            ]);

            return false;
        } finally {
            tenancy()->end();
        }
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $initialized_here = false;
        $tenant_id = $request->hasSession() ? $request->session()->get('tenant_id') : null;
        $tenant_id = $tenant_id ?: $request->cookie('tenant_id');

        if ($tenant_id && !tenancy()->initialized) {
            $tenant = Tenant::on('central')->find($tenant_id);

            if ($tenant && $this->reachable($tenant)) {
                tenancy()->initialize($tenant);
                $initialized_here = true;

                if ($request->hasSession() && !$request->session()->get('tenant_id')) {
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
        if (!tenancy()->initialized) {
            $guard = Auth::guard();

            Auth::forgetUser();

            /**
             * Ook het id uit de sessie halen, en niet alleen de opgehaalde
             * gebruiker vergeten. forgetUser() gooit het object weg, maar het id
             * staat nog in de sessie: de guard haalt hem daarna gewoon opnieuw
             * op en zoekt de users-tabel dan in de centrale database, waar hij
             * niet staat. Dat was een 500 op elke pagina, ook op het
             * inlogscherm, dus er viel ook niet meer uit te komen.
             */
            if ($request->hasSession() && $guard instanceof SessionGuard) {
                $request->session()->forget($guard->getName());
            }

            $recaller = $guard->getRecallerName();

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
