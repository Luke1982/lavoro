<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Planning\Clock;
use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\Users\NameListChange;
use App\Models\Event;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * An existing appointment was moved onto another werkbon, or taken off one.
 *
 * The werkbonnen hear it as ServiceOrderScheduled and ServiceOrderUnscheduled,
 * which say nothing on a timeline because a werkbon shows its appointments
 * already. That leaves the werkbon the appointment left without a trace of it,
 * and the appointment without a record of where it used to hang; this line is
 * for those two, and not for the werkbon it came onto, which shows it anyway.
 * Not an AppointmentSignal: the customer is not mailed for it.
 */
class AppointmentServiceOrderChanged extends BaseSignal
{
    public function __construct(public Event $event, public NameListChange $service_orders)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'appointment.service_order_changed';
    }

    public static function label(): string
    {
        return 'Werkbon van afspraak gewijzigd';
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
        $left = $this->service_orders->removed();
        $joined = $this->service_orders->added();

        return 'Afspraak' . $moment . match (true) {
            $left !== [] && $joined !== [] => ' verplaatst van ' . NameListChange::names($left)
                . ' naar ' . NameListChange::names($joined),
            $joined !== [] => ' gekoppeld aan ' . NameListChange::names($joined),
            default => ' losgekoppeld van ' . NameListChange::names($left),
        };
    }

    public function changes(): array
    {
        return [$this->service_orders->change('service_orders', 'Werkbon')];
    }

    /** @return array<int, ServiceOrder> */
    public function activityContext(): array
    {
        $left = $this->service_orders->removedIds();

        return $left === [] ? [] : ServiceOrder::whereKey($left)->get()->all();
    }
}
