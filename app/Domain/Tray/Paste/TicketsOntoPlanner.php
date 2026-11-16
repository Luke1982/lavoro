<?php

namespace App\Domain\Tray\Paste;

use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\NewServiceOrder;
use App\Actions\Tickets\AttachTicketToServiceOrderAction;
use App\Domain\Tray\TicketFacts;
use App\Domain\Tray\TrayKinds;
use App\Exceptions\Refusal;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\Ticket;
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
    public function question(Paste $paste): Question
    {
        $slot = new PlannerSlot($paste);

        return new Question(
            title: Str::ucfirst(TrayKinds::counted('ticket', $paste->records->count())) . ' inplannen',
            subtitle: $slot->subtitle(),
            items: TicketFacts::listed($paste->records),
            choices: $this->choices($paste, $slot),
            legend: 'Op welke werkbon?',
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $slot = new PlannerSlot($paste);
        $gate = Gate::forUser($paste->user);
        $gate->authorize('create', Event::class);

        $loose = $paste->records->filter(fn (Ticket $ticket) => $ticket->service_order_id === null);
        $loose->each(fn (Ticket $ticket) => $gate->authorize('attachToServiceOrder', $ticket));

        if ($slot->wantsNewOrder()) {
            $gate->authorize('create', ServiceOrder::class);
            $order = $this->newOrder($paste);
        } else {
            $order = $slot->chosenOrder();
            $loose->each(fn (Ticket $ticket) => app(AttachTicketToServiceOrderAction::class)->execute($order, $ticket));
        }

        return $slot->plan($order);
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
    private function choices(Paste $paste, PlannerSlot $slot): array
    {
        $tickets = $paste->records;
        $order_ids = $tickets->pluck('service_order_id')->unique();

        if ($order_ids->count() === 1 && $order_ids->first() !== null) {
            return [$slot->orderChoice(
                $tickets->first()->serviceOrder,
                already: $tickets->count() === 1 ? 'De storing staat er al op.' : 'De storingen staan er al op.',
            )];
        }

        if ($order_ids->contains(fn ($id) => $id !== null)) {
            throw new Refusal('Een deel van deze storingen staat al op een werkbon. '
                . 'Pak ze los uit de stapel en plan ze apart in.');
        }

        $customer = TicketFacts::sharedCustomer($tickets);
        $count = $tickets->count();

        return [
            new Choice(
                value: PlannerSlot::NEW_ORDER,
                label: 'Nieuwe werkbon',
                description: 'Voor ' . $customer->name . ', met '
                    . ($count === 1 ? 'de storing' : 'alle ' . $count . ' storingen') . ' erop.',
                confirm: 'Werkbon maken en inplannen',
            ),
            ...$slot->openOrderChoices($customer),
        ];
    }
}
