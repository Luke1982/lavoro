<?php

namespace App\Http\Controllers;

use App\Jobs\Google\TeardownIntegrationJob;
use App\Models\GoogleCalendarIntegration;
use App\Services\Google\GoogleClientFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class GoogleOAuthController extends Controller
{
    public function __construct(private GoogleClientFactory $client_factory) {}

    public function start(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->hasPermission('google_calendar.connect') || Auth::user()->isAdmin(), 403);

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        $client = $this->client_factory->unauthenticatedClient();
        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected_state = $request->session()->pull('google_oauth_state');
        if (!$expected_state || !hash_equals($expected_state, (string) $request->query('state'))) {
            return redirect()->route('me.edit')->with('error', __('google.oauth_state_mismatch'));
        }

        abort_unless(Auth::user()->hasPermission('google_calendar.connect') || Auth::user()->isAdmin(), 403);

        if ($request->has('error')) {
            return redirect()->route('me.edit')->with('error', __('google.oauth_denied'));
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            return redirect()->route('me.edit')->with('error', __('google.oauth_no_code'));
        }

        $client = $this->client_factory->unauthenticatedClient();
        $token_set = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token_set['error'])) {
            return redirect()->route('me.edit')->with('error', __('google.oauth_token_exchange_failed'));
        }

        $granted_scopes = explode(' ', $token_set['scope'] ?? '');
        if (!in_array('https://www.googleapis.com/auth/calendar', $granted_scopes, true)) {
            $this->revokeAccessToken($token_set['access_token'] ?? null);
            return redirect()->route('me.edit')->with('error', __('google.oauth_missing_calendar_scope'));
        }

        $client->setAccessToken($token_set);
        $oauth = new \Google\Service\Oauth2($client);
        $userinfo = $oauth->userinfo->get();

        $user = Auth::user();

        $incoming_refresh = $token_set['refresh_token'] ?? null;
        $existing = GoogleCalendarIntegration::where('user_id', $user->id)->first();

        if (!$incoming_refresh && !$existing) {
            $this->revokeAccessToken($token_set['access_token']);
            return redirect()->route('me.edit')->with('error', __('google.oauth_no_refresh_token'));
        }

        $attributes = [
            'google_account_email' => $userinfo->email,
            'google_account_sub' => $userinfo->id,
            'access_token' => $token_set['access_token'],
            'expires_at' => now()->addSeconds((int) ($token_set['expires_in'] ?? 3600)),
            'scopes' => explode(' ', $token_set['scope'] ?? ''),
            'connected_at' => now(),
            'disabled_at' => null,
            'last_error' => null,
        ];
        if ($incoming_refresh) {
            $attributes['refresh_token'] = $incoming_refresh;
        }

        $integration = GoogleCalendarIntegration::updateOrCreate(
            ['user_id' => $user->id],
            $attributes,
        );

        $service_api = app(\App\Services\Google\GoogleCalendarApi::class);
        $google_cal = $service_api->createCalendar($integration, config('google.calendar_summary_own'));

        try {
            $synced_cal = \Illuminate\Support\Facades\DB::transaction(function () use ($integration, $user, $google_cal) {
                return \App\Models\GoogleSyncedCalendar::create([
                    'google_calendar_integration_id' => $integration->id,
                    'owner_user_id' => $user->id,
                    'google_calendar_id' => $google_cal->getId(),
                    'summary' => $google_cal->getSummary(),
                ]);
            });
        } catch (\Throwable $e) {
            try {
                $service_api->deleteCalendar($integration, $google_cal->getId());
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }

        \App\Jobs\Google\BackfillCalendarJob::dispatch($synced_cal->id);

        $grants_received = \App\Models\CalendarGrant::where('viewer_user_id', $user->id)->get();
        $grant_service = app(\App\Services\Google\GrantSyncService::class);
        foreach ($grants_received as $grant) {
            $grant_service->onGrantCreated($grant);
        }

        return redirect()->route('me.edit')->with('success', __('google.connected'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $integration = Auth::user()->googleCalendarIntegration;
        if ($integration) {
            TeardownIntegrationJob::dispatch($integration->id);
        }
        return redirect()->route('me.edit')->with('success', __('google.disconnect_started'));
    }

    private function revokeAccessToken(?string $access_token): void
    {
        if (!$access_token) {
            return;
        }
        try {
            \Illuminate\Support\Facades\Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
                'token' => $access_token,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Google access token revoke failed (non-fatal)',
                ['error' => $e->getMessage()],
            );
        }
    }
}
