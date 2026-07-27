<?php

namespace App\Listeners\Appointments;

use App\Domain\Signals\Appointments\AppointmentSignal;
use App\Enums\StandardEmailTriggerType;
use App\Jobs\SendStandardEmailJob;
use App\Models\StandardEmailTrigger;
use App\Services\StandardEmailTriggerResolver;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Sends whatever standard e-mails an administrator has hung on this moment.
 * Owns that concern entirely: the code that schedules or cancels an appointment
 * has no idea any e-mail exists.
 */
class SendAppointmentStandardEmails implements ShouldHandleEventsAfterCommit
{
    public function handle(AppointmentSignal $signal): void
    {
        $trigger = $signal->emailTrigger();

        if ($trigger === null) {
            return;
        }

        $appointment = $signal->appointment();

        StandardEmailTriggerResolver::matching(
            $appointment,
            $trigger,
            [StandardEmailTriggerType::background->name],
        )->each(fn (StandardEmailTrigger $match) => SendStandardEmailJob::dispatch(
            $appointment->id,
            $match->standard_email_id,
            $trigger->name,
        ));
    }
}
