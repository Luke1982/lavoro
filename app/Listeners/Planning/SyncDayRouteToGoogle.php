<?php

namespace App\Listeners\Planning;

use App\Domain\Signals\Planning\DayRouteAssigned;
use App\Domain\Signals\Planning\DayRouteRemoved;
use App\Jobs\Google\DeleteDayRouteFromGoogleJob;
use App\Jobs\Google\PushDayRouteJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Keeps the all-day route item in the person's Google calendars in step with their day. */
class SyncDayRouteToGoogle implements ShouldHandleEventsAfterCommit
{
    public function handleAssigned(DayRouteAssigned $signal): void
    {
        PushDayRouteJob::dispatch($signal->route->id);
    }

    public function handleRemoved(DayRouteRemoved $signal): void
    {
        if ($signal->google_items !== []) {
            DeleteDayRouteFromGoogleJob::dispatch($signal->google_items);
        }
    }
}
