<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\Users\NameListChange;
use App\Models\Customer;
use App\Models\Event;
use Illuminate\Database\Eloquent\Model;

/**
 * An appointment came onto a customer directly, or left one: an appointment
 * without a werkbon is for a customer through this link and nothing else, so
 * on the customer's timeline this is the line that says it was planned there.
 * Moving onto a werkbon takes the direct link away, and that is said here too,
 * except to the werkbon's own customer: the appointment is still theirs.
 */
class AppointmentCustomerChanged extends BaseSignal
{
    use ReadLikeTheAppointment;

    public function __construct(public Event $event, public NameListChange $customers)
    {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'appointment.customer_changed';
    }

    public static function label(): string
    {
        return 'Klant van afspraak gewijzigd';
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
        return $this->event->historyLabel() . ': ' . $this->customers->sentence('gekoppeld', 'losgekoppeld');
    }

    public function changes(): array
    {
        return [$this->customers->change('customers', 'Klant')];
    }

    /** @return array<int, Customer> */
    public function activityContext(): array
    {
        $ids = [...$this->customers->addedIds(), ...$this->customers->removedIds()];

        return $ids === [] ? [] : Customer::whereKey($ids)->get()->all();
    }
}
