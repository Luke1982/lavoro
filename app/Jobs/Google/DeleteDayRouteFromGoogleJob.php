<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use App\Services\Google\GoogleCalendarApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Takes a route that left the planning out of the Google calendars it was put in. */
class DeleteDayRouteFromGoogleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 1800, 3600, 7200];

    /** @param  array<int|string, string>  $google_items  Google's event id per synced calendar id */
    public function __construct(public array $google_items) {}

    public function handle(GoogleCalendarApi $api): void
    {
        $calendars = GoogleSyncedCalendar::query()
            ->whereIn('id', array_keys($this->google_items))
            ->with('integration')
            ->get();

        foreach ($calendars as $calendar) {
            if ($calendar->integration === null || $calendar->integration->isDisabled()) {
                continue;
            }

            $api->deleteEvent($calendar->integration, $calendar->google_calendar_id, $this->google_items[$calendar->id]);
        }
    }
}
