<?php

namespace App\Domain\Tray\Paste;

use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\Tickets\AttachTicketToServiceOrderAction;
use App\Domain\Tray\Kinds\TicketKind;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Storingen dropped on a technician's day in the planner.
 *
 * An appointment hangs off a werkbon, so the storingen need one first. When they
 * all go the same way, one question covers them: storingen on one werkbon plan
 * that werkbon, and loose storingen of one customer go on a new werkbon or on
 * one of that customer's open ones. A mixed stack is asked about storing by
 * storing, and any of them can stay on the bakje. Every werkbon that comes out
 * of it is planned where they were dropped.
 */
class TicketsOntoPlanner implements PasteHandler
{
    private const LEAVE = 'leave';

    public function question(Paste $paste): Question
    {
        $slot = new PlannerSlot($paste);
        $tickets = $paste->records;

        if ($this->goTogether($tickets)) {
            return new Question(
                title: $paste->heading() . ' inplannen',
                subtitle: $slot->subtitle(),
                items: TicketKind::listed($tickets),
                choices: $this->choicesForAll($paste, $slot),
                legend: 'Op welke werkbon?',
            );
        }

        return new Question(
            title: $paste->heading() . ' inplannen',
            subtitle: $slot->subtitle() . '. Ze gaan niet allemaal dezelfde kant op, dus kies per storing.',
            parts: $this->parts($tickets, $slot),
            confirm: 'Inplannen',
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $slot = new PlannerSlot($paste);
        $by_answer = $paste->records->groupBy(fn (Ticket $ticket) => $paste->answerFor($ticket));
        $left = $by_answer->pull(self::LEAVE, collect());

        if ($by_answer->isEmpty()) {
            throw new Refusal('Je hebt geen storing gekozen om in te plannen.');
        }

        $gate = Gate::forUser($paste->user);
        $gate->authorize('create', Event::class);
        $by_answer->flatten(1)
            ->whereNull('service_order_id')
            ->each(fn (Ticket $ticket) => $gate->authorize('attachToServiceOrder', $ticket));

        if ($by_answer->has(PlannerSlot::NEW_ORDER)) {
            $gate->authorize('create', ServiceOrder::class);
        }

        $orders = $by_answer->flatMap(fn (Collection $tickets, string $answer) => $answer === PlannerSlot::NEW_ORDER
            ? $this->newOrders($tickets)
            : [$this->onto($slot->order($answer), $tickets)]);

        $planned = $slot->plan(...$orders->all());

        return new PasteResult(
            $planned->message . TicketKind::leftAlone($left, 'blijft op het bakje', 'blijven op het bakje'),
            created: $planned->created,
            kept: $left->pluck('id')->all(),
        );
    }

    /** All on the same werkbon, or all loose and of one customer. */
    private function goTogether(Collection $tickets): bool
    {
        $order_ids = $tickets->pluck('service_order_id')->unique();

        if ($order_ids->count() !== 1) {
            return false;
        }

        if ($order_ids->first() !== null) {
            return true;
        }

        $customer_ids = $tickets->map(fn (Ticket $ticket) => TicketKind::customerId($ticket))->unique();

        return $customer_ids->count() === 1 && $customer_ids->first() !== null;
    }

    /**
     * @return array<int, Choice>
     */
    private function choicesForAll(Paste $paste, PlannerSlot $slot): array
    {
        $order = $paste->records->first()->serviceOrder;

        if ($order) {
            return [$slot->orderChoice(
                $order,
                already: $paste->records->count() === 1 ? 'De storing staat er al op.' : 'De storingen staan er al op.',
            )];
        }

        return $slot->choices(TicketKind::sharedCustomer($paste->records), 'met ' . $paste->every() . ' erop');
    }

    /**
     * Each storing with its own choices, and always the choice to leave it on
     * the bakje. When that is the only choice for every one of them, there is
     * nothing to ask, only why.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, QuestionPart>
     */
    private function parts(Collection $tickets, PlannerSlot $slot): array
    {
        $customers = Customer::findMany(
            $tickets->map(fn (Ticket $ticket) => TicketKind::customerId($ticket))->filter()->unique(),
        )->keyBy('id');
        $customer_of = fn (Ticket $ticket) => $customers->get(TicketKind::customerId($ticket));
        $blocked = $tickets->mapWithKeys(fn (Ticket $ticket) => [
            $ticket->id => $this->blocked($ticket, $customer_of($ticket)),
        ]);

        if ($blocked->filter()->count() === $tickets->count()) {
            throw new Refusal($tickets
                ->map(fn (Ticket $ticket) => TicketKind::named($ticket) . ': ' . lcfirst($blocked[$ticket->id]))
                ->implode(' '));
        }

        return $tickets->map(fn (Ticket $ticket) => $this->part(
            $ticket,
            $customer_of($ticket),
            $blocked[$ticket->id],
            $tickets,
            $slot,
        ))->all();
    }

    /**
     * @param  Collection<int, Ticket>  $stack
     */
    private function part(
        Ticket $ticket,
        ?Customer $customer,
        ?string $blocked,
        Collection $stack,
        PlannerSlot $slot,
    ): QuestionPart {
        $order = $ticket->serviceOrder;

        return new QuestionPart(
            key: $ticket->id,
            title: TicketKind::title($ticket),
            subtitle: $order
                ? 'Staat op werkbon ' . $order->number
                : 'Nog op geen werkbon' . ($customer ? ', ' . $customer->name : ''),
            choices: [
                ...match (true) {
                    $blocked !== null => [],
                    $order !== null => [$slot->orderChoice($order, already: 'De storing staat er al op.')],
                    default => $this->looseChoices($customer, $stack, $slot),
                },
                new Choice(self::LEAVE, 'Niet inplannen', trim($blocked . ' Blijft op het bakje liggen.')),
            ],
        );
    }

    /** Why this storing cannot be planned here, or null when it can. */
    private function blocked(Ticket $ticket, ?Customer $customer): ?string
    {
        $order = $ticket->serviceOrder;

        return match (true) {
            $order !== null && $order->is_closed => 'Werkbon ' . $order->number . ' is al afgesloten.',
            $order === null && $customer === null => 'Hangt niet aan een machine van een klant.',
            default => null,
        };
    }

    /**
     * A loose storing can join a werkbon another storing in the stack is on; go
     * on a new werkbon, shared by every loose storing of its customer that picks
     * it; or go on one of its customer's other open werkbonnen.
     *
     * @param  Collection<int, Ticket>  $stack
     * @return array<int, Choice>
     */
    private function looseChoices(Customer $customer, Collection $stack, PlannerSlot $slot): array
    {
        $joining = $stack
            ->filter(fn (Ticket $other) => $other->serviceOrder
                && !$other->serviceOrder->is_closed
                && (int) $other->serviceOrder->customer_id === $customer->id)
            ->unique('service_order_id')
            ->map(fn (Ticket $other) => $slot->orderChoice(
                $other->serviceOrder,
                already: TicketKind::named($other) . ' staat er al op.',
            ));
        $loose_of_customer = $stack->filter(fn (Ticket $other) => $other->service_order_id === null
            && TicketKind::customerId($other) === $customer->id);
        $sharing = $loose_of_customer->count() > 1
            ? ' Kies je dit bij meer storingen van deze klant, dan komen ze samen op één nieuwe werkbon.'
            : '';

        return collect([
            ...$joining,
            new Choice(PlannerSlot::NEW_ORDER, 'Nieuwe werkbon', 'Voor ' . $customer->name . '.' . $sharing),
            ...$slot->openOrderChoices($customer),
        ])->unique(fn (Choice $choice) => $choice->value)->values()->all();
    }

    /**
     * New werkbonnen, one per customer, through the same action the werkbon
     * screen uses, which hangs the storingen off it as it goes.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return Collection<int, ServiceOrder>
     */
    private function newOrders(Collection $tickets): Collection
    {
        return $tickets
            ->groupBy(fn (Ticket $ticket) => TicketKind::customerId($ticket))
            ->map(fn (Collection $same_customer) => app(CreateServiceOrderAction::class)
                ->execute(TicketKind::newServiceOrder($same_customer)))
            ->values();
    }

    /**
     * An existing werkbon, with the loose storingen that chose it hung off it.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    private function onto(ServiceOrder $order, Collection $tickets): ServiceOrder
    {
        $tickets
            ->whereNull('service_order_id')
            ->each(fn (Ticket $ticket) => app(AttachTicketToServiceOrderAction::class)->execute($order, $ticket));

        return $order;
    }
}
