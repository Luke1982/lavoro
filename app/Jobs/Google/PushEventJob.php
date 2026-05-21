<?php

namespace App\Jobs\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $event_id)
    {
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
