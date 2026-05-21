<?php

namespace App\Jobs\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use App\Services\Google\GoogleCalendarApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BackfillCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $google_synced_calendar_id)
    {
    }

    public function handle(GoogleCalendarApi $api): void
    {
        $cal = GoogleSyncedCalendar::find($this->google_synced_calendar_id);
        if (!$cal || $cal->integration->isDisabled()) {
            return;
        }

        $owner_id = $cal->owner_user_id;
        $lookback = now()->subDays(config('google.sync_lookback_days'));

        $event_ids = Event::whereHas('executingUsers', fn ($q) => $q->where('users.id', $owner_id))
            ->where('end', '>=', $lookback)
            ->orderBy('start')
            ->pluck('id');

        $integration = $cal->integration;
        $integration->update([
            'backfill_total' => $event_ids->count(),
            'backfill_done' => 0,
        ]);

        if (config('google.webhook_enabled')) {
            $this->registerWatch($cal, $api);
        }

        foreach ($event_ids as $event_id) {
            PushEventToCalendarJob::dispatch($event_id, $cal->id, true);
        }
    }

    private function registerWatch(\App\Models\GoogleSyncedCalendar $cal, GoogleCalendarApi $api): void
    {
        $channel_id = (string) \Illuminate\Support\Str::uuid();
        $token = \Illuminate\Support\Str::random(40);
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
            'watch_expires_at' => \Carbon\Carbon::createFromTimestampMs($result['expiration']),
        ]);
    }
}
