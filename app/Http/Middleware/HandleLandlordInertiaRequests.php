<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia for the admin panel.
 *
 * A middleware of its own and not the customer app's: that one shares user,
 * permissions, menu and tenant data, and none of that exists here. The panel
 * runs centrally and never has a tenant.
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
             * All three keys. 'message' belongs among them because the handling
             * of an expired page puts its explanation there; without it that
             * message appeared nowhere and a form seemed to fail silently.
             */
            /**
             * For the one form that cannot go over Inertia: the collection file
             * comes back as a download, and a download can only come from an
             * ordinary form submission.
             */
            'csrf_token' => fn () => csrf_token(),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
                'message' => fn () => $request->session()->get('message'),
            ],
        ];
    }
}
