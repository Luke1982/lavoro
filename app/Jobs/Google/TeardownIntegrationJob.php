<?php

namespace App\Jobs\Google;

use App\Models\GoogleCalendarIntegration;
use App\Services\Google\GoogleClientFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TeardownIntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $integration_id)
    {
    }

    public function handle(GoogleClientFactory $client_factory): void
    {
        $integration = GoogleCalendarIntegration::find($this->integration_id);
        if (!$integration) {
            return;
        }

        foreach ($integration->syncedCalendars as $cal) {
            if ($cal->watch_channel_id && $cal->watch_resource_id) {
                try {
                    app(\App\Services\Google\GoogleCalendarApi::class)
                        ->stopWatch($integration, $cal->watch_channel_id, $cal->watch_resource_id);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('stopWatch failed during teardown', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        foreach ($integration->syncedCalendars as $cal) {
            try {
                app(\App\Services\Google\GoogleCalendarApi::class)
                    ->deleteCalendar($integration, $cal->google_calendar_id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('deleteCalendar failed during teardown', [
                    'cal_id' => $cal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->revokeRefreshToken($integration);

        $integration->syncedCalendars()->each(fn ($cal) => $cal->delete());
        $integration->delete();
    }

    private function revokeRefreshToken(GoogleCalendarIntegration $integration): void
    {
        try {
            Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
                'token' => $integration->refresh_token,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google token revoke failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }
}
