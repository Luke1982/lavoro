<?php

namespace App\Listeners\Appointments;

use App\Domain\Signals\Appointments\AppointmentCancelled;

/**
 * A werkbon that was only planned because an appointment existed goes back to
 * the planning-cancelled stage when that appointment is dropped.
 */
class ReleaseServiceOrdersFromPlanning
{
    public function handle(AppointmentCancelled $signal): void
    {
        if ($signal->permanent) {
            return;
        }

        $signal->appointment()
            ->serviceOrders
            ->each(fn ($service_order) => $service_order->revertToPlanningCancelledStage());
    }
}
