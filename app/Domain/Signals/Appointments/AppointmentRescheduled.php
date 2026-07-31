<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Signals\BaseSignal;
use App\Enums\EventTrigger;
use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AppointmentRescheduled extends BaseSignal implements AppointmentSignal
{
    /**
     * Dit signaal gaat over elke wijziging aan een afspraak, ook eentje waarbij
     * alleen de omschrijving anders is. Of de afspraak ook echt verschoven is, is
     * een tweede feit, en dat wordt hier vastgelegd op het moment dat het nog te
     * weten valt: vlak na de opslag kent het model zijn vorige waarden nog, een
     * luisteraar verderop niet meer.
     */
    public bool $moved_in_time = false;

    public ?Carbon $previous_start = null;

    public ?Carbon $previous_end = null;

    public function __construct(public Event $event)
    {
        parent::__construct();

        $this->moved_in_time = $event->wasChanged(['start', 'end']);
        $this->previous_start = $this->momentBefore($event, 'start');
        $this->previous_end = $this->momentBefore($event, 'end');
    }

    public static function key(): string
    {
        return 'appointment.rescheduled';
    }

    public static function label(): string
    {
        return 'Afspraak gewijzigd';
    }

    public function appointment(): Event
    {
        return $this->event;
    }

    public function emailTrigger(): ?EventTrigger
    {
        return EventTrigger::event_updated;
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

    /**
     * De waarde van voor de opslag, ruw opgehaald en zelf omgezet: die kolom mag
     * leeg zijn, en een lege datum is geen datum maar niets.
     */
    private function momentBefore(Event $event, string $field): ?Carbon
    {
        $raw = $event->getRawOriginal($field);

        return $raw ? Carbon::parse($raw) : null;
    }
}
