<?php

namespace App\Domain\Signals\Users;

/** Someone may now see another person's appointments in their own Google Calendar. */
class CalendarAccessGranted extends CalendarAccessSignal
{
    public static function key(): string
    {
        return 'user.calendar_access_granted';
    }

    public static function label(): string
    {
        return 'Agenda gedeeld';
    }

    protected function describe(): ?string
    {
        if (!$this->viewerMayBeNamed()) {
            return null;
        }

        return 'Afspraken van ' . $this->ownerName() . ' zichtbaar gemaakt in de agenda van ' . $this->viewerName();
    }
}
