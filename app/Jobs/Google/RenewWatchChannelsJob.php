<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use App\Services\Google\GoogleCalendarApi;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RenewWatchChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(GoogleCalendarApi $api): void
    {
        if (!config('google.webhook_enabled')) {
            return;
        }

        $threshold = now()->addHours(24);
        $candidates = GoogleSyncedCalendar::query()
            ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
            ->whereNotNull('watch_expires_at')
            ->where('watch_expires_at', '<=', $threshold)
            ->get();

        foreach ($candidates as $cal) {
            if ($cal->watch_channel_id && $cal->watch_resource_id) {
                $api->stopWatch($cal->integration, $cal->watch_channel_id, $cal->watch_resource_id);
            }

            $channel_id = (string) Str::uuid();
            $token = Str::random(40);
            $ttl = 7 * 24 * 60 * 60;
            $result = $api->watchCalendar(
                $cal->integration,
                $cal->google_calendar_id,
                $channel_id,
                $token,
                config('google.webhook_url'),
                $ttl
            );
            $cal->update([
                'watch_channel_id' => $channel_id,
                'watch_channel_token' => $token,
                'watch_resource_id' => $result['resource_id'],
                'watch_expires_at' => Carbon::createFromTimestampMs($result['expiration']),
            ]);
        }
    }
}
