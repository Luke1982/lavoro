<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Signals\Signal;
use App\Enums\EventTrigger;
use App\Models\Event;

/**
 * Something happened to an appointment. Listeners type-hint this interface to
 * react to every appointment fact, or a concrete class to react to one.
 *
 * Adding a reaction means adding a listener class. Nothing that causes an
 * appointment fact ever needs to know who is listening.
 */
interface AppointmentSignal extends Signal
{
    public function appointment(): Event;

    /**
     * The configured trigger this fact corresponds to, or null when it is not a
     * moment users can hang a standard e-mail on.
     */
    public function emailTrigger(): ?EventTrigger;

    /** Whether the appointment still exists after this fact. */
    public function appointmentStillExists(): bool;
}
