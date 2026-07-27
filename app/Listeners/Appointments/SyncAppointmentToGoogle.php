<?php

namespace App\Listeners\Appointments;

use App\Domain\Signals\Appointments\AppointmentCancelled;
use App\Domain\Signals\Appointments\AppointmentSignal;
use App\Jobs\Google\DeleteEventFromGoogleJob;
use App\Jobs\Google\PushEventJob;
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
}
