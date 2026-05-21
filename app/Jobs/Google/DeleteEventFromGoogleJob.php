<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use App\Models\GoogleSyncedEvent;
use App\Services\Google\CalendarSyncService;
use App\Services\Google\GoogleCalendarApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteEventFromGoogleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 1800, 3600, 7200];

    public function __construct(
        public int $google_synced_event_id,
        public ?int $google_synced_calendar_id = null,
        public ?string $google_event_id = null,
    ) {
    }

    public function handle(CalendarSyncService $sync, GoogleCalendarApi $api): void
    {
        $mapping = GoogleSyncedEvent::find($this->google_synced_event_id);
        if ($mapping) {
            if ($mapping->syncedCalendar?->integration?->isDisabled()) {
                return;
            }
            $sync->deleteEvent($mapping);
            return;
        }

        if (!$this->google_synced_calendar_id || !$this->google_event_id) {
            return;
        }
        $cal = GoogleSyncedCalendar::find($this->google_synced_calendar_id);
        if (!$cal || $cal->integration?->isDisabled()) {
            return;
        }
        $api->deleteEvent($cal->integration, $cal->google_calendar_id, $this->google_event_id);
    }
}
