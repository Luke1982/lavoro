<?php

namespace App\Http\Middleware;

use App\Models\GeneralSetting;
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
