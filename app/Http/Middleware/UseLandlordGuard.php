<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Zet de standaardguard op landlord voor het beheerpaneel.
 *
 * Niet alleen netjes, maar nodig: de database-sessiedriver schrijft user_id mee
 * en haalt dat op met auth()->guard()->id() -- de standaardguard dus. Op een
 * route zonder tenant zoekt die web-guard de gebruiker in de centrale database,
 * waar geen users-tabel staat, en dat is een 500 bij het wegschrijven van de
 * sessie in plaats van iets bij het lezen ervan.
 */
class UseLandlordGuard
{
    public function handle(Request $request, Closure $next): mixed
    {
        Auth::shouldUse('landlord');

        return $next($request);
    }
}
