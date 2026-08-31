<?php

namespace App\Http\Middleware;

use App\Enums\TicketStatusses;
use App\Models\Assistant;
use App\Models\GeneralSetting;
use App\Models\InternalAnnouncement;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user_data = null;
        if ($request->user()) {
            $integration = $request->user()->hasPermission('google_calendar.connect')
                ? $request->user()->googleCalendarIntegration
                : null;

            $user_data = array_merge(
                $request->user()->only(['id', 'name', 'email', 'avatar']),
                [
                    'roles' => $request->user()->roles()->pluck('name')->all(),
                    'google_integration' => $integration
                        ? [
                            'email' => $integration->google_account_email,
                            'disabled_at' => $integration->disabled_at,
                        ]
                        : null,
                ]
            );
        }

        return [
            ...parent::share($request),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'extra' => $request->session()->get('extra'),
            ],
            'auth' => [
                'user' => $user_data,
                'permissions' => $request->user() ? $request->user()->permissionNames() : [],
                'isAdmin' => $request->user() ? $request->user()->isAdmin() : false,

                /**
                 * Verdicts, not the evidence. A rule with an exception in it —
                 * the assistant is the one thing being an admin does not grant —
                 * has to be decided in one place, and that place is the policy.
                 * Anything that asks the front end to reason about permissions
                 * is a second implementation waiting to disagree with the first.
                 */
                'can' => [
                    'use_assistant' => $request->user()?->can('use', Assistant::class) ?? false,
                ],
            ],
            /**
             * What the menu paints on top of itself: the dot beside Storingen and
             * the count on the bell. Booleans and counts only — the menu's shape
             * lives in menu.json, and this says nothing about what it looks like.
             *
             * The storingen dot asks whether any exist rather than how many, which
             * is a cheaper question and the only one a dot can answer.
             */
            'nav' => [
                'open_tickets' => $request->user()
                    ? Ticket::visibleTo($request->user())
                        ->where('status', '!=', TicketStatusses::gesloten->value)
                        ->exists()
                    : false,
                'unread_notifications' => $request->user()
                    ? $request->user()->userNotifications()->unread()->count()
                    : 0,
            ],
            /**
             * De aankondiging die deze gebruiker nu moet bevestigen, of null.
             *
             * De naam is met opzet lang. Een paginaprop overschrijft een
             * gedeelde prop met dezelfde sleutel, en 'announcement' is precies
             * hoe de detailpagina zijn eigen record noemt: daar las de balk dan
             * de aankondiging van de pagina en ging hij nooit meer weg.
             *
             * Hier en niet in een eigen route: elke Inertia-navigatie ververst
             * dit al, en na een bevestiging stuurt de controller terug naar
             * dezelfde pagina, waarna de volgende openstaande aankondiging
             * vanzelf in deze prop staat. Alleen wat de balk tekent, want de
             * rest van het record gaat niemand aan die het moet lezen.
             *
             * Als functie, zodat de zoekopdracht alleen draait als er een
             * Inertia-pagina getekend wordt. Elk formulier dat opslaat eindigt
             * in een redirect, en die hoeft hier niet voor te betalen.
             */
            'pendingAnnouncement' => fn () => $request->user()
                ? InternalAnnouncement::openFor($request->user())
                    ?->only(['id', 'title', 'body'])
                : null,
            /**
             * The public half of the VAPID keypair, which the browser needs in
             * hand to subscribe at all. Null when the installation has no keys,
             * which is the front end's cue not to ask for permission it could
             * never act on.
             */
            /** Het pakket dat deze klant afneemt, voor de licentiekaart in het menu. */
            'tenant' => tenancy()->initialized ? [
                'package' => optional(\App\Models\Central\Package::on('central')
                    ->where('key', tenancy()->tenant->package_key)->first())->name,
                'modules' => tenancy()->tenant->modules ?? [],
            ] : null,
            'push' => [
                'vapid_public_key' => $request->user() ? config('webpush.public_key') : null,
            ],
            'location_tracking' => $request->user() ? (function () {
                $rows = GeneralSetting::whereIn('key', [
                    'location_tracking_start',
                    'location_tracking_end',
                    'location_tracking_days',
                ])->pluck('value', 'key');

                return [
                    'start' => $rows->get('location_tracking_start', '07:00'),
                    'end' => $rows->get('location_tracking_end', '18:00'),
                    'days' => array_map('intval', explode(',', $rows->get('location_tracking_days', '1,2,3,4,5'))),
                ];
            })() : null,
        ];
    }
}
