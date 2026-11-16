<?php

namespace App\Domain\Tray\Paste;

use App\Actions\Appointments\AppointmentAssignment;
use App\Actions\Appointments\CreateAppointmentAction;
use App\Actions\Appointments\NewAppointment;
use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\NewServiceOrder;
use App\Actions\Tickets\AttachTicketToServiceOrderAction;
use App\Domain\Planning\Clock;
use App\Domain\Tray\TicketFacts;
use App\Domain\Tray\TrayKinds;
use App\Exceptions\Refusal;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Storingen dropped on a technician's day in the planner.
 *
 * An appointment hangs off a werkbon, so the storingen need one first. Loose
 * ones get a choice: a new werkbon for their customer, or one of that customer's
 * werkbonnen that is still open. Storingen already on the same werkbon plan that
 * werkbon. Anything in between is refused rather than guessed at.
 */
class TicketsOntoPlanner implements PasteHandler
{
    private const NEW_ORDER = 'new';

    private const OPEN_ORDERS_OFFERED = 5;

    public function question(Paste $paste): Question
    {
        return new Question(
            title: Str::ucfirst(TrayKinds::counted('ticket', $paste->records->count())) . ' inplannen',
            subtitle: 'Bij ' . $this->technician($paste)->name . ', ' . $this->when($paste),
            items: TicketFacts::listed($paste->records),
            choices: $this->choices($paste),
            legend: 'Op welke werkbon?',
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $gate = Gate::forUser($paste->user);
        $gate->authorize('create', Event::class);

        $loose = $paste->records->filter(fn (Ticket $ticket) => $ticket->service_order_id === null);
        $loose->each(fn (Ticket $ticket) => $gate->authorize('attachToServiceOrder', $ticket));

        if ($paste->answer === self::NEW_ORDER) {
            $gate->authorize('create', ServiceOrder::class);
            $order = $this->newOrder($paste);
        } else {
            $order = ServiceOrder::findOrFail((int) Str::after($paste->answer, 'serviceorder:'));
            $loose->each(fn (Ticket $ticket) => app(AttachTicketToServiceOrderAction::class)->execute($order, $ticket));
        }

        $technician = $this->technician($paste);

        app(CreateAppointmentAction::class)->execute(new NewAppointment(
            attributes: [
                'name' => mb_strimwidth($order->description ?: 'Werkbon ' . $order->number, 0, 255, '…'),
                'description' => $order->description ?? '',
                'event_type_id' => (int) $paste->target['event_type_id'],
                'status' => 'Gepland',
                'start' => $paste->target['start'],
                'end' => $paste->target['end'],
            ],
            eventable_type: '\\' . ServiceOrder::class,
            eventable_id: $order->id,
            assignment: new AppointmentAssignment(user_ids: [$technician->id]),
        ));

        return new PasteResult(
            'Werkbon ' . $order->number . ' ingepland bij ' . $technician->name . ', ' . $this->when($paste) . '.',
            created: ['service_order_id' => $order->id],
        );
    }

    /** Through the same action the werkbon screen uses, which hangs the storingen off it as it goes. */
    private function newOrder(Paste $paste): ServiceOrder
    {
        return app(CreateServiceOrderAction::class)->execute(new NewServiceOrder(
            customer_id: TicketFacts::sharedCustomer($paste->records)->id,
            location_id: TicketFacts::sharedLocationId($paste->records),
            description: TicketFacts::serviceOrderDescription($paste->records),
            ticket_ids: $paste->records->pluck('id')->all(),
        ));
    }

    /**
     * @return array<int, Choice>
     */
    private function choices(Paste $paste): array
    {
        $tickets = $paste->records;
        $order_ids = $tickets->pluck('service_order_id')->unique();

        if ($order_ids->count() === 1 && $order_ids->first() !== null) {
            return [$this->existingOrder($tickets->first()->serviceOrder, already_on_it: $tickets->count())];
        }

        if ($order_ids->contains(fn ($id) => $id !== null)) {
            throw new Refusal('Een deel van deze storingen staat al op een werkbon. '
                . 'Pak ze los uit de stapel en plan ze apart in.');
        }

        $customer = TicketFacts::sharedCustomer($tickets);
        $count = $tickets->count();

        $open_orders = ServiceOrder::visibleTo($paste->user)
            ->where('customer_id', $customer->id)
            ->whereDoesntHave('serviceOrderStage', fn ($query) => $query->closesOrder())
            ->withCount('events')
            ->latest('id')
            ->limit(self::OPEN_ORDERS_OFFERED)
            ->get();

        return [
            new Choice(
                value: self::NEW_ORDER,
                label: 'Nieuwe werkbon',
                description: 'Voor ' . $customer->name . ', met '
                    . ($count === 1 ? 'de storing' : 'alle ' . $count . ' storingen') . ' erop.',
                confirm: 'Werkbon maken en inplannen',
            ),
            ...$open_orders->map(fn (ServiceOrder $order) => $this->existingOrder($order))->all(),
        ];
    }

    private function existingOrder(ServiceOrder $order, int $already_on_it = 0): Choice
    {
        if ($order->is_closed) {
            throw new Refusal('Werkbon ' . $order->number . ' is al afgesloten.');
        }

        $state = match (true) {
            $already_on_it === 1 => 'De storing staat er al op.',
            $already_on_it > 1 => 'De storingen staan er al op.',
            $order->events_count > 0 => 'Al eerder ingepland.',
            default => 'Nog niet ingepland.',
        };

        return new Choice(
            value: 'serviceorder:' . $order->id,
            label: ($already_on_it > 0 ? 'Werkbon ' : 'Bij open werkbon ') . $order->number,
            description: ($order->description ?: 'Zonder omschrijving') . '. ' . $state,
            confirm: $already_on_it > 0 ? 'Inplannen' : 'Toevoegen en inplannen',
        );
    }

    private function technician(Paste $paste): User
    {
        return User::findOrFail($paste->target['user_id']);
    }

    /** "woensdag 18 november 09:00–10:00", on the clock the planner shows. */
    private function when(Paste $paste): string
    {
        $start = Clock::toLocal($paste->target['start'] . ' UTC')->locale('nl');
        $end = Clock::toLocal($paste->target['end'] . ' UTC');

        return $start->isoFormat('dddd D MMMM') . ' ' . $start->format('H:i') . '–' . $end->format('H:i');
    }
}
