<?php

namespace App\Observers;

use App\Domain\Signals\Appointments\AppointmentCancelled;
use App\Domain\Signals\Appointments\AppointmentRescheduled;
use App\Domain\Signals\Appointments\AppointmentRestored;
use App\Domain\Signals\Appointments\AppointmentScheduled;
use App\Models\Event;

/**
 * Announces what happened to an appointment and nothing else. Google sync,
 * standard e-mails and werkbon planning are handled by listeners that this class
 * knows nothing about, so a new reaction is a new listener rather than an edit
 * here.
 */
class EventObserver
{
    public function created(Event $event): void
    {
        event(new AppointmentScheduled($event));
    }

    public function updated(Event $event): void
    {
        event(new AppointmentRescheduled($event));
    }

    public function deleting(Event $event): void
    {
        if (!$event->isForceDeleting()) {
            return;
        }

        event(new AppointmentCancelled($event, permanent: true));
    }

    public function deleted(Event $event): void
    {
        if ($event->isForceDeleting()) {
            return;
        }

        event(new AppointmentCancelled($event));
    }

    public function restored(Event $event): void
    {
        event(new AppointmentRestored($event));
    }
}
