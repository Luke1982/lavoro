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
     * Is this customer's database still there?
     *
     * tenancy()->initialize() only swaps the settings over and notices nothing;
     * the first question to the database then breaks. For a customer that was
     * half created or half cleaned up that meant a 500 on every page, the login
     * screen included -- the whole installation down over one broken customer.
     *
     * If connecting fails we act as if there is no customer: the session is
     * forgotten and you end up on the login screen, where you can go on.
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
         * Without a tenant nothing can be logged in: the users table lives in
         * the tenant database. Laravel's remember-me restores the user only
         * after this middleware, so without this it fetches one anyway and
         * Auth::user() asks the central database for a table that is not there
         * -- a 500 instead of the login screen.
         */
        if (!tenancy()->initialized) {
            $guard = Auth::guard();

            Auth::forgetUser();

            /**
             * Take the id out of the session too, and not only forget the
             * fetched user. forgetUser() throws the object away, but the id is
             * still in the session: the guard simply fetches it again afterwards
             * and looks for the users table in the central database, where it is
             * not. That was a 500 on every page, the login screen included, so
             * there was no way out of it either.
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
