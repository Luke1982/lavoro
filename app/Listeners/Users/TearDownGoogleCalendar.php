<?php

namespace App\Listeners\Users;

use App\Domain\Signals\Users\GoogleCalendarDisconnected;
use App\Jobs\Google\TeardownIntegrationJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Removes what the link made at Google, in the background. */
class TearDownGoogleCalendar implements ShouldHandleEventsAfterCommit
{
    public function handle(GoogleCalendarDisconnected $signal): void
    {
        TeardownIntegrationJob::dispatch($signal->integration->id);
    }
}
