<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use App\Services\Google\CalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PullCalendarChangesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 1800, 3600, 7200];

    public function __construct(public int $google_synced_calendar_id)
    {
    }

    public function handle(CalendarSyncService $sync): void
    {
        $cal = GoogleSyncedCalendar::find($this->google_synced_calendar_id);
        if (!$cal || $cal->integration->isDisabled()) {
            return;
        }
        $sync->pullChanges($cal);
    }
}
