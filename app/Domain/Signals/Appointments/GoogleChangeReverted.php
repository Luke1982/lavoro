<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Signals\BaseSignal;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone changed an appointment in their Google Calendar and the change was
 * not taken over: they may not make it here, or the field is kept in this
 * application. Google was put back the way the appointment is. Without this
 * line the person sees their change vanish and nobody can tell them why.
 */
class GoogleChangeReverted extends BaseSignal
{
    use ReadLikeTheAppointment;

    /** Per kind: what was undone, and why. */
    private const REASONS = [
        'uncancel-unauthorized' => ['Het terughalen van de afgezegde afspraak', 'geen recht om deze afspraak te wijzigen'],
        'delete' => ['Het verwijderen', 'geen recht om deze afspraak te verwijderen'],
        'time' => ['Het verzetten', 'geen recht om deze afspraak te wijzigen'],
        'text-lavoro-origin' => ['De gewijzigde naam, omschrijving of locatie', 'deze afspraak is hier gemaakt en wordt hier bijgehouden'],
        'text-unauthorized' => ['De gewijzigde naam, omschrijving of locatie', 'geen recht om deze afspraak te wijzigen'],
        'location-owned-by-lavoro' => ['De nieuwe locatie', 'de locatie wordt hier bijgehouden'],
    ];

    public function __construct(public Event $event, public User $calendar_owner, public string $kind)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'appointment.google_change_reverted';
    }

    public static function label(): string
    {
        return 'Wijziging uit Google Agenda teruggedraaid';
    }

    public function subject(): Model
    {
        return $this->event;
    }

    public function activityCategory(): string
    {
        return 'warning';
    }

    /** MajorLabel's own accounts are not named in a customer's trail. */
    public function activityDescription(): ?string
    {
        if ($this->calendar_owner->isSuperAdmin()) {
            return null;
        }

        [$what, $why] = self::REASONS[$this->kind] ?? ['Een wijziging', 'niet overgenomen'];

        return $what . ' in de Google Agenda van ' . $this->calendar_owner->name . ' is teruggedraaid: ' . $why;
    }

    /** Which appointment, for the timelines below where it is not the page's own record. */
    public function activityMetadata(): ?array
    {
        return ['kind' => $this->kind, 'subject_label' => $this->event->historyLabel()];
    }

    /**
     * No screen shows an appointment's own timeline. The person whose change
     * vanished looks on their profile, and the planner on the werkbon.
     *
     * @return array<int, User|ServiceOrder>
     */
    public function activityContext(): array
    {
        return [$this->calendar_owner, ...$this->event->serviceOrders()->get()->all()];
    }
}
