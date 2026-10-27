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
            Log::warning("A tenant's database is unreachable; session ignored", [
                'tenant' => $tenant->getTenantKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            tenancy()->end();
        }
    }

    /**
     * A session that points at a user who is no longer in the database.
     *
     * The guard looks the id up in the customer's database, finds nothing and
     * returns null -- but leaves the id in the session, because only logging
     * out takes it out. The session is written again after this middleware has
     * ended tenancy, and the database session driver asks the guard for that id
     * one more time to store it. That second lookup lands on the central
     * database, which has no users table at all: a 500 on every page, for as
     * long as that session lives, and the login screen is no way out because it
     * happens there too.
     *
     * So the id goes now, while the customer's database is still open and the
     * answer means something. Deleting a user leaves such a session behind, and
     * so does importing a customer's installation again: the database is
     * replaced and whoever was logged in is no longer in it.
     */
    private function forgetAVanishedUser(Request $request): void
    {
        $guard = Auth::guard();

        if (!$request->hasSession() || !$guard instanceof SessionGuard) {
            return;
        }

        if (!$request->session()->has($guard->getName())) {
            return;
        }

        /** Resolved against the tenant connection, which is still the open one. */
        if ($guard->user() !== null) {
            return;
        }

        $request->session()->forget($guard->getName());

        $recaller = $guard->getRecallerName();

        $request->cookies->remove($recaller);
        cookie()->queue(cookie()->forget($recaller));
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

        /**
         * Before the request, not after it: a request whose user is gone ends
         * in an AuthenticationException, and the pipeline then unwinds without
         * running anything that sits after $next(). Checking here also means
         * the request itself carries on as a guest, which is a login screen
         * rather than an error.
         */
        if (tenancy()->initialized) {
            $this->forgetAVanishedUser($request);
        }

        $response = $next($request);

        if ($initialized_here && tenancy()->initialized) {
            tenancy()->end();
        }

        return $response;
    }
}
