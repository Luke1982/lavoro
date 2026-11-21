<?php

namespace App\Domain\Signals\Users;

/**
 * Someone may no longer see another person's appointments in their Google
 * Calendar. Made before the grant is deleted and carries it as it was: by the
 * time the listeners run the row itself is gone.
 */
class CalendarAccessRevoked extends CalendarAccessSignal
{
    public static function key(): string
    {
        return 'user.calendar_access_revoked';
    }

    public static function label(): string
    {
        return 'Agenda niet langer gedeeld';
    }

    protected function describe(): ?string
    {
        if (!$this->viewerMayBeNamed()) {
            return null;
        }

        return 'Afspraken van ' . $this->ownerName() . ' niet langer zichtbaar in de agenda van ' . $this->viewerName();
    }
}
