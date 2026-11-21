<?php

namespace App\Listeners\Appointments;

use App\Domain\Signals\Appointments\AppointmentCancelled;
use App\Domain\Signals\Appointments\AppointmentCrewChanged;
use App\Domain\Signals\Appointments\AppointmentCustomerChanged;
use App\Domain\Signals\Appointments\AppointmentServiceOrderChanged;
use App\Domain\Signals\Appointments\AppointmentSignal;
use App\Domain\Signals\ServiceOrders\ServiceOrderCustomerChanged;
use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
use App\Models\Event;
use App\Models\GoogleSyncedEvent;
use App\Models\Scopes\HidesSuperAdmins;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Keeps Google Calendar in step with an appointment. Owns that concern entirely:
 * nothing that changes an appointment needs to know Google exists.
 *
 * A cancellation reads its calendar mappings off the signal rather than the
 * database, because by the time this runs the appointment and its mappings may
 * already be gone.
 */
class SyncAppointmentToGoogle implements ShouldHandleEventsAfterCommit
{
    public function handle(AppointmentSignal $signal): void
    {
        if ($signal->appointmentStillExists()) {
            PushEventJob::dispatch($signal->appointment()->id);

            return;
        }

        if (!$signal instanceof AppointmentCancelled) {
            return;
        }

        foreach ($signal->synced_mappings as $mapping) {
            DeleteEventFromGoogleJob::dispatch(
                $mapping['id'],
                $mapping['calendar_id'],
                $mapping['google_event_id'],
            );
        }
    }

    /**
     * Whoever came onto the appointment gets it in their calendar, and whoever
     * was taken off stops seeing it there. A crew put on an appointment while it
     * is being made needs neither: the push for the new appointment carries it.
     */
    public function handleCrewChanged(AppointmentCrewChanged $signal): void
    {
        if ($signal->appointment_is_new) {
            return;
        }

        PushEventJob::dispatch($signal->event->id);

        $this->dropCopiesOfPeopleNoLongerOnIt($signal->event);
    }

    /** The title in Google names the customer and the werkbon. */
    public function handleServiceOrderChanged(AppointmentServiceOrderChanged $signal): void
    {
        PushEventJob::dispatch($signal->event->id);
    }

    /**
     * The address in Google is the customer's when the appointment has no
     * werkbon. A new appointment's own push already carries its customer.
     */
    public function handleCustomerChanged(AppointmentCustomerChanged $signal): void
    {
        if ($signal->event->wasRecentlyCreated) {
            return;
        }

        PushEventJob::dispatch($signal->event->id);
    }

    /**
     * The title in Google names the werkbon's customer, so a werkbon moved to
     * another customer is pushed again for every appointment it has -- from the
     * planner and from the werkbon screen alike.
     */
    public function handleServiceOrderCustomerChanged(ServiceOrderCustomerChanged $signal): void
    {
        $signal->service_order->events()->pluck('events.id')
            ->each(fn ($event_id) => PushEventJob::dispatch((int) $event_id));
    }

    /**
     * Any Google copy in the calendar of a person who is neither an owner nor an
     * executor of the appointment any more is removed.
     *
     * Read past the scope that hides MajorLabel's accounts: this runs in the
     * customer's request, and a super admin still on the appointment would
     * otherwise lose their copy on every crew change, only for the worker --
     * which sees everyone -- to push it back.
     */
    private function dropCopiesOfPeopleNoLongerOnIt(Event $event): void
    {
        $still_relevant = array_unique(array_merge(
            $event->owners()->withoutGlobalScope(HidesSuperAdmins::class)
                ->wherePivot('type', 'owner')->pluck('users.id')->all(),
            $event->executingUsers()->withoutGlobalScope(HidesSuperAdmins::class)
                ->pluck('users.id')->all(),
        ));

        GoogleSyncedEvent::whereHas(
            'syncedCalendar',
            fn ($query) => $query->whereNotIn('owner_user_id', $still_relevant),
        )->where('event_id', $event->id)->get()
            ->each(fn ($synced) => DeleteEventFromGoogleJob::dispatch(
                $synced->id,
                $synced->google_synced_calendar_id,
                $synced->google_event_id,
            ));
    }
}
