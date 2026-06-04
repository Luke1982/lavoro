<?php

namespace App\Observers;

use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
use App\Models\Event;
use App\Models\GoogleSyncedEvent;

class EventObserver
{
    public function created(Event $event): void
    {
        PushEventJob::dispatch($event->id);
    }

    public function updated(Event $event): void
    {
        PushEventJob::dispatch($event->id);
    }

    public function deleting(Event $event): void
    {
        if (!$event->isForceDeleting()) {
            return;
        }
        $mappings = GoogleSyncedEvent::where('event_id', $event->id)->get();
        foreach ($mappings as $mapping) {
            DeleteEventFromGoogleJob::dispatch(
                $mapping->id,
                $mapping->google_synced_calendar_id,
                $mapping->google_event_id,
            );
        }
    }

    public function deleted(Event $event): void
    {
        if ($event->isForceDeleting()) {
            return;
        }
        $mappings = GoogleSyncedEvent::where('event_id', $event->id)->get();
        foreach ($mappings as $mapping) {
            DeleteEventFromGoogleJob::dispatch(
                $mapping->id,
                $mapping->google_synced_calendar_id,
                $mapping->google_event_id,
            );
        }
        foreach ($event->serviceOrders as $service_order) {
            $service_order->revertToPlanningCancelledStage();
        }
    }

    public function restored(Event $event): void
    {
        PushEventJob::dispatch($event->id);
    }
}
