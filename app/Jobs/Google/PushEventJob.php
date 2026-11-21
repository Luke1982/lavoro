<?php

namespace App\Jobs\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Pushes an appointment to the calendar of everyone on it, as it is when this
 * runs. One save can announce several facts that each ask for a push, and every
 * push is a call to Google per calendar; while one is still waiting, another
 * would only send the same appointment again, so it is not queued. Once this
 * starts, the next push queues as normal: it may carry a later change.
 */
class PushEventJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Should a lock never be released -- a worker dying mid-way -- pushes queue again after this many seconds. */
    public int $uniqueFor = 60;

    public function __construct(public int $event_id)
    {
    }

    /** Appointment ids are per customer, so the customer is part of what makes a push the same one. */
    public function uniqueId(): string
    {
        return tenant()?->getTenantKey() . ':' . $this->event_id;
    }

    /**
     * The cache as it is for this customer now. The one the container hands out
     * is made once per process, and in a worker that is before the customer of
     * this job is known, so taking and releasing the lock could use two
     * different prefixes.
     */
    public function uniqueVia(): Repository
    {
        return Cache::store();
    }

    public function handle(): void
    {
        $event = Event::find($this->event_id);
        if (!$event) {
            return;
        }

        $owner_ids = $event->owners()->wherePivot('type', 'owner')->pluck('users.id')->all();
        $executing_ids = $event->executingUsers()->pluck('users.id')->all();
        $relevant_user_ids = array_unique(array_merge($owner_ids, $executing_ids));

        if (empty($relevant_user_ids)) {
            return;
        }

        $synced_calendars = GoogleSyncedCalendar::whereIn('owner_user_id', $relevant_user_ids)
            ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
            ->get();

        foreach ($synced_calendars as $cal) {
            PushEventToCalendarJob::dispatch($event->id, $cal->id);
        }
    }
}
