<?php

namespace App\Listeners\Users;

use App\Domain\Signals\Users\GoogleCalendarConnected;
use App\Jobs\Google\BackfillCalendarJob;
use App\Models\CalendarGrant;
use App\Services\Google\GrantSyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Fills a freshly linked Google Calendar: the person's own appointments, and a
 * calendar for everyone whose appointments they were already allowed to see.
 */
class StartGoogleCalendarSync implements ShouldHandleEventsAfterCommit
{
    public function __construct(private GrantSyncService $grants) {}

    public function handle(GoogleCalendarConnected $signal): void
    {
        BackfillCalendarJob::dispatch($signal->calendar->id);

        CalendarGrant::where('viewer_user_id', $signal->user->id)->get()
            ->each(fn (CalendarGrant $grant) => $this->grants->onGrantCreated($grant));
    }
}
