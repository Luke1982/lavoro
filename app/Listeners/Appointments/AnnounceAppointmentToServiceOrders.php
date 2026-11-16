<?php

namespace App\Listeners\Appointments;

use App\Domain\Signals\Appointments\AppointmentCancelled;
use App\Domain\Signals\Appointments\AppointmentRescheduled;
use App\Domain\Signals\Appointments\AppointmentRestored;
use App\Domain\Signals\ServiceOrders\ServiceOrderScheduled;
use App\Domain\Signals\ServiceOrders\ServiceOrderUnscheduled;
use App\Domain\Signals\Signals;
use App\Models\Event;

/**
 * Says what happened to an appointment in terms of the werkbonnen it is on, so
 * whoever cares about a werkbon's planning listens to two facts instead of
 * learning every way an appointment can come and go.
 *
 * A permanent delete is left out, as it is for the werkbon's stage.
 */
class AnnounceAppointmentToServiceOrders
{
    public function handleCancelled(AppointmentCancelled $signal): void
    {
        if (!$signal->permanent) {
            $this->announce($signal->event, ServiceOrderUnscheduled::class);
        }
    }

    public function handleRestored(AppointmentRestored $signal): void
    {
        $this->announce($signal->event, ServiceOrderScheduled::class);
    }

    public function handleRescheduled(AppointmentRescheduled $signal): void
    {
        if ($signal->cancellation_changed) {
            $this->announce(
                $signal->event,
                $signal->event->isCancelled() ? ServiceOrderUnscheduled::class : ServiceOrderScheduled::class,
            );
        }
    }

    /** @param  class-string<ServiceOrderScheduled|ServiceOrderUnscheduled>  $fact */
    private function announce(Event $appointment, string $fact): void
    {
        foreach ($appointment->serviceOrders as $service_order) {
            Signals::dispatch(new $fact($service_order, $appointment));
        }
    }
}
