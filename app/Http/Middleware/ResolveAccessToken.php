<?php

namespace App\Http\Middleware;

use App\Enums\AccessTokenPurpose;
use App\Models\AccessToken;
use App\Models\Tenant;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the link in the url into the token behind it, or does not let the
 * visitor through.
 *
 * The purpose sits in the middleware's own argument and not in the url, so a
 * link for one screen never opens another:
 *
 *     ->middleware('accesstoken:ticket.customer_upload')
 *
 * What is found goes into the container and not into the route parameter.
 * Laravel fills route parameters itself through SubstituteBindings, which runs
 * before a route's middleware: a controller taking AccessToken by the
 * parameter's name would first get a lookup by id thrown at it. Through the
 * container it arrives intact, type and all.
 */
class ResolveAccessToken
{
    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        $wanted = AccessTokenPurpose::tryFrom($purpose);

        if ($wanted === null) {
            abort(500, 'Unknown kind of access link: ' . $purpose);
        }

        $value = $request->route('token');

        /**
         * Nothing found is not found. A 403 would confirm the link once existed,
         * and that is exactly what someone guessing wants to know.
         */
        $tenant = is_string($value) ? $this->tenantOf($value) : null;

        if ($tenant === null) {
            abort(404);
        }

        return Tenancy::within($tenant, fn () => $this->pass($request, $next, $wanted, $value));
    }

    private function pass(Request $request, Closure $next, AccessTokenPurpose $wanted, string $value): Response
    {
        $token = AccessToken::resolve($value, $wanted);

        if ($token === null) {
            abort(404);
        }

        if ($token->isExpired()) {
            return Inertia::render('Public/LinkExpiredPage', [
                'purpose' => $token->purpose->label(),
                'expired_on' => $token->expires_at,
            ])->toResponse($request)->setStatusCode(410);
        }

        app()->instance(AccessToken::class, $token);

        return $next($request);
    }

    /**
     * The visitor has no session, so the link is the only thing that says
     * whose it is.
     */
    private function tenantOf(string $value): ?Tenant
    {
        $key = AccessToken::tenantKeyOf($value);

        return $key === null
            ? $this->tenantHolding(AccessToken::hash($value))
            : Tenant::on('central')->find($key);
    }

    /**
     * Links handed out before the tenant was part of them. They expire after
     * customerupload.token_days; until then every reachable database is asked
     * whether it knows the hash.
     */
    private function tenantHolding(string $hash): ?Tenant
    {
        return Tenant::on('central')->cursor()->first(fn (Tenant $tenant) => Tenancy::reachable($tenant)
            && Tenancy::within($tenant, fn () => AccessToken::where('token_hash', $hash)->exists()));
    }
}
