<?php

namespace App\Observers;

use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
use App\Jobs\SendStandardEmailJob;
use App\Models\Event;
use App\Models\GoogleSyncedEvent;
use App\Models\StandardEmailTrigger;
use App\Services\StandardEmailTriggerResolver;

class EventObserver
{
    public function created(Event $event): void
    {
        PushEventJob::dispatch($event->id);
        $this->dispatchBackgroundStandardEmails($event, EventTrigger::event_created);
    }

    public function updated(Event $event): void
    {
        PushEventJob::dispatch($event->id);
        $this->dispatchBackgroundStandardEmails($event, EventTrigger::event_updated);
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
        $this->dispatchBackgroundStandardEmails($event, EventTrigger::event_deleted);
    }

    public function restored(Event $event): void
    {
        PushEventJob::dispatch($event->id);
    }

    private function dispatchBackgroundStandardEmails(Event $event, EventTrigger $trigger): void
    {
        StandardEmailTriggerResolver::matching($event, $trigger, [StandardEmailTriggerType::background->name])
            ->each(function (StandardEmailTrigger $match) use ($event, $trigger) {
                SendStandardEmailJob::dispatch($event->id, $match->standard_email_id, $trigger->name);
            });
    }
}
