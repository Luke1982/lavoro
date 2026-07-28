<?php

namespace App\Actions\Appointments;

use App\Models\Event;

/**
 * Cancels an appointment.
 *
 * Deliberately thin: deleting the event is the whole act, and EventObserver
 * already turns that into an AppointmentCancelled signal, which is what releases
 * the werkbon from planning and removes the Google copy. The value here is the
 * seam — the planner, the API and a tool call all cancel through one method, so
 * when cancellation grows a reason or a notification it grows in one place.
 */
class CancelAppointmentAction
{
    public function execute(Event $event): void
    {
        $event->delete();
    }
}
