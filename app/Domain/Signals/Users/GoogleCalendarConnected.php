<?php

namespace App\Domain\Signals\Users;

use App\Models\GoogleCalendarIntegration;
use App\Models\GoogleSyncedCalendar;
use App\Models\User;

/**
 * Someone linked their Google Calendar: their appointments, and those of the
 * people whose calendars were shared with them, go there from now on.
 */
class GoogleCalendarConnected extends UserSignal
{
    public function __construct(
        User $user,
        public GoogleCalendarIntegration $integration,
        public GoogleSyncedCalendar $calendar,
    ) {
        parent::__construct($user);
    }

    public static function key(): string
    {
        return 'user.google_calendar_connected';
    }

    public static function label(): string
    {
        return 'Google Agenda gekoppeld';
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    /**
     * Without the Google account it was linked to: that may be a private
     * address, and the timeline is read by everyone who may see this person.
     */
    protected function describe(): ?string
    {
        return 'Google Agenda gekoppeld';
    }
}
