<?php

namespace App\Jobs\Google;

use App\Domain\Planning\Clock;
use App\Models\DayRoute;
use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use App\Services\Google\GoogleCalendarApi;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class BackfillCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $google_synced_calendar_id) {}

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

        $day_route_ids = DayRoute::query()->where('user_id', $owner_id)->where('date', '>=', Clock::today())->pluck('id');

        foreach ($day_route_ids as $day_route_id) {
            PushDayRouteJob::dispatch($day_route_id);
        }
    }

    private function registerWatch(GoogleSyncedCalendar $cal, GoogleCalendarApi $api): void
    {
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
