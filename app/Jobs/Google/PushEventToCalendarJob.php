<?php

namespace App\Jobs\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use App\Services\Google\CalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushEventToCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 1800, 3600, 7200];

    public function __construct(
        public int $event_id,
        public int $google_synced_calendar_id,
        public bool $is_backfill = false,
    ) {
    }

    public function handle(CalendarSyncService $sync): void
    {
        $event = Event::find($this->event_id);
        $cal = GoogleSyncedCalendar::find($this->google_synced_calendar_id);
        if (!$event || !$cal) {
            return;
        }
        if ($cal->integration?->isDisabled()) {
            return;
        }
        $sync->pushEvent($event, $cal);

        if (!$this->is_backfill) {
            return;
        }

        $integration = $cal->integration;
        if ($integration->backfill_total !== null && ($integration->backfill_done ?? 0) < $integration->backfill_total) {
            $integration->increment('backfill_done');
        }
    }
}
