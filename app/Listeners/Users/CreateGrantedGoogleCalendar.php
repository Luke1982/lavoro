<?php

namespace App\Listeners\Users;

use App\Domain\Signals\Users\CalendarAccessGranted;
use App\Services\Google\GrantSyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Makes the shared calendar in the viewer's Google account, once the grant is
 * saved for good. A failure at Google is not swallowed: whoever granted access
 * should hear that the calendar did not come.
 */
class CreateGrantedGoogleCalendar implements ShouldHandleEventsAfterCommit
{
    public function __construct(private GrantSyncService $grants) {}

    public function handle(CalendarAccessGranted $signal): void
    {
        $this->grants->onGrantCreated($signal->grant);
    }
}
