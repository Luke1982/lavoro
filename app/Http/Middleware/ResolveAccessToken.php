<?php

namespace App\Http\Middleware;

use App\Enums\AccessTokenPurpose;
use App\Models\AccessToken;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zet de link uit de url om in het token erachter, of laat de bezoeker niet
 * verder.
 *
 * Het doel staat in het argument van de middleware zelf en niet in de url, zodat
 * een link voor het ene scherm nooit een ander scherm opent:
 *
 *     ->middleware('accesstoken:ticket.customer_upload')
 *
 * Wat gevonden wordt gaat de container in en niet de routeparameter. Laravel
 * vult routeparameters zelf al in via SubstituteBindings, en dat draait vóór de
 * middleware van een route: een controller die AccessToken op naam van de
 * parameter zou opvangen, kreeg dan eerst een zoekopdracht op id over zich heen.
 * Via de container komt hij ongeschonden aan, met type en al.
 */
class ResolveAccessToken
{
    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        $wanted = AccessTokenPurpose::tryFrom($purpose);

        if ($wanted === null) {
            abort(500, 'Onbekend soort toegangslink: ' . $purpose);
        }

        $value = $request->route('token');

        $token = is_string($value) ? AccessToken::resolve($value, $wanted) : null;

        /**
         * Niets gevonden is niet gevonden. Een 403 zou bevestigen dat de link
         * ooit bestaan heeft, en dat is precies wat iemand die aan het raden is
         * wil weten.
         */
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
}
