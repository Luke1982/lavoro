<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Signals\BaseSignal;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone made an appointment in their own Google Calendar and it was taken
 * over here. The appointment is created without the usual announcements -- it
 * came from Google, so pushing it back or mailing about it would be wrong --
 * which makes this the only line that says where it came from.
 *
 * Not an AppointmentSignal, for the same reason.
 */
class AppointmentImportedFromGoogle extends BaseSignal
{
    public function __construct(public Event $event, public User $calendar_owner)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'appointment.imported_from_google';
    }

    public static function label(): string
    {
        return 'Afspraak overgenomen uit Google Agenda';
    }

    public function subject(): Model
    {
        return $this->event;
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    /** On the calendar owner's profile the line says which appointment it was. */
    public function activityMetadata(): ?array
    {
        return ['subject_label' => $this->event->historyLabel()];
    }

    /** MajorLabel's own accounts are not named in a customer's trail. */
    public function activityDescription(): ?string
    {
        if ($this->calendar_owner->isSuperAdmin()) {
            return null;
        }

        return 'Afspraak overgenomen uit de Google Agenda van ' . $this->calendar_owner->name;
    }

    /** @return array<int, User> */
    public function activityContext(): array
    {
        return [$this->calendar_owner];
    }
}
