<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Planning\Clock;
use App\Domain\Signals\BaseSignal;
use App\Enums\EventTrigger;
use App\Models\Event;
use App\Models\Location;
use Illuminate\Database\Eloquent\Model;

/**
 * The location an appointment was at was deleted, and the appointment moved to
 * another location of the customer or to none. Told on the appointment and its
 * werkbonnen; at the customer the location's own "Verwijderd" is the news.
 *
 * An appointment fact, so Google gets the new address; but not a moment anyone
 * hung a standard e-mail on: nothing about the visit itself changed, and a
 * customer has no use for a mail about an office being tidied up.
 */
class AppointmentLocationDeleted extends BaseSignal implements AppointmentSignal
{
    use ReadLikeTheAppointment;

    public function __construct(
        public Event $event,
        public Location $location,
        public ?Location $target = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'appointment.location_deleted';
    }

    public static function label(): string
    {
        return 'Locatie van afspraak verwijderd';
    }

    public function appointment(): Event
    {
        return $this->event;
    }

    public function emailTrigger(): ?EventTrigger
    {
        return null;
    }

    public function appointmentStillExists(): bool
    {
        return true;
    }

    public function subject(): Model
    {
        return $this->event;
    }

    public function activityCategory(): string
    {
        return 'event';
    }

    public function activityDescription(): ?string
    {
        $moment = $this->event->start ? ' van ' . Clock::toLocal($this->event->start)->format('d-m-Y H:i') : '';
        $name = $this->event->name ? ' "' . $this->event->name . '"' : '';
        $now = $this->target
            ? 'de afspraak is verplaatst naar locatie ' . $this->target->displayName()
            : 'de afspraak heeft nu geen locatie';

        return 'Afspraak' . $name . $moment . ': locatie ' . $this->location->displayName() . ' is verwijderd, ' . $now;
    }

    public function changes(): array
    {
        return [[
            'field' => 'location_id',
            'label' => 'Locatie',
            'old_value' => (string) $this->location->id,
            'new_value' => $this->target === null ? null : (string) $this->target->id,
            'old_label' => $this->location->displayName(),
            'new_label' => $this->target?->displayName(),
        ]];
    }

    public function activityContext(): array
    {
        return $this->event->serviceOrders->all();
    }
}
