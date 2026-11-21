<?php

namespace App\Domain\Signals\Users;

use App\Models\GoogleCalendarIntegration;
use App\Models\User;

/** Someone unlinked their Google Calendar; taking it apart at Google follows. */
class GoogleCalendarDisconnected extends UserSignal
{
    public function __construct(User $user, public GoogleCalendarIntegration $integration)
    {
        parent::__construct($user);
    }

    public static function key(): string
    {
        return 'user.google_calendar_disconnected';
    }

    public static function label(): string
    {
        return 'Google Agenda ontkoppeld';
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    protected function describe(): ?string
    {
        return 'Koppeling met Google Agenda verbroken';
    }
}
