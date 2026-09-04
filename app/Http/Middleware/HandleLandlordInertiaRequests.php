<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia voor het beheerpaneel.
 *
 * Een eigen middleware en niet die van de klant-app: die deelt gebruiker,
 * rechten, menu en tenantgegevens, en dat bestaat hier allemaal niet. Het
 * paneel draait centraal en heeft nooit een tenant.
 */
class HandleLandlordInertiaRequests extends Middleware
{
    protected $rootView = 'landlord.app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'email' => $request->user('landlord')?->email,
            ],
            /**
             * Alle drie de sleutels. 'message' hoort erbij omdat de afhandeling
             * van een verlopen pagina zijn uitleg daar neerzet; zonder dat kwam
             * die melding nergens in beeld en leek een formulier stil te falen.
             */
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
                'message' => fn () => $request->session()->get('message'),
            ],
        ];
    }
}
