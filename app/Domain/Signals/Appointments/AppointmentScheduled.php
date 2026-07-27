<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Signals\BaseSignal;
use App\Enums\EventTrigger;
use App\Models\Event;
use Illuminate\Database\Eloquent\Model;

class AppointmentScheduled extends BaseSignal implements AppointmentSignal
{
    public function __construct(public Event $event)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'appointment.scheduled';
    }

    public static function label(): string
    {
        return 'Afspraak ingepland';
    }

    public function appointment(): Event
    {
        return $this->event;
    }

    public function emailTrigger(): ?EventTrigger
    {
        return EventTrigger::event_created;
    }

    public function appointmentStillExists(): bool
    {
        return true;
    }

    public function subject(): Model
    {
        return $this->event;
    }

    public function activityDescription(): ?string
    {
        return null;
    }
}
