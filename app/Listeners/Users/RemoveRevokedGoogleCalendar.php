<?php

namespace App\Listeners\Users;

use App\Domain\Signals\Users\CalendarAccessRevoked;
use App\Services\Google\GrantSyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Takes the shared calendar out of the viewer's Google account once the grant is gone. */
class RemoveRevokedGoogleCalendar implements ShouldHandleEventsAfterCommit
{
    public function __construct(private GrantSyncService $grants) {}

    public function handle(CalendarAccessRevoked $signal): void
    {
        $this->grants->onGrantRevoked($signal->grant);
    }
}
