<?php

namespace App\Domain\Signals\Appointments;

/**
 * For an appointment fact that also lands on a werkbon's or a customer's
 * timeline. The line names the appointment's moment and often who goes, and
 * those timelines are read by people the planner would not show that
 * appointment to: a monteur on the werkbon sees only their own appointments.
 * So the line is read under the same permission as other people's appointments.
 */
trait ReadLikeTheAppointment
{
    public function requiredPermission(): ?string
    {
        return 'event.see_all';
    }
}
