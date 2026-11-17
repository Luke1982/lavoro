<?php

namespace App\Domain\Tray\Paste;

use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Domain\Tray\Kinds\TicketKind;
use App\Exceptions\Refusal;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Support\Facades\Gate;

/**
 * Storingen dropped on a project: a new werkbon under that project, with the
 * storingen on it. Asked first, because it makes a werkbon.
 */
class TicketsOntoProject implements PasteHandler
{
    public function question(Paste $paste): Question
    {
        $project = $this->project($paste);

        return new Question(
            title: $paste->heading() . ' onder het project',
            subtitle: $project->title . ', ' . TicketKind::sharedCustomer($paste->records)->name . '.',
            items: TicketKind::listed($paste->records),
            choices: [new Choice(
                value: 'new',
                label: 'Nieuwe werkbon onder dit project',
                description: 'Met ' . $paste->every() . ' erop.',
                confirm: 'Werkbon maken',
            )],
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $project = $this->project($paste);
        $gate = Gate::forUser($paste->user);

        $gate->authorize('create', ServiceOrder::class);
        $paste->records->each(fn (Ticket $ticket) => $gate->authorize('attachToServiceOrder', $ticket));

        $order = app(CreateServiceOrderAction::class)
            ->execute(TicketKind::newServiceOrder($paste->records, $project->id));

        return new PasteResult(
            'Werkbon ' . $order->number . ' staat nu onder ' . $project->title . '.',
            created: ['service_order_id' => $order->id],
        );
    }

    /** Storingen already on a werkbon are not free to take. */
    private function project(Paste $paste): Project
    {
        $on_order = $paste->records->first(fn (Ticket $ticket) => $ticket->service_order_id !== null);

        if ($on_order) {
            throw new Refusal('"' . TicketKind::title($on_order) . '" staat al op werkbon '
                . $on_order->serviceOrder?->number . '. Haal hem daar eerst af.');
        }

        return DropTarget::projectFor($paste, TicketKind::sharedCustomer($paste->records));
    }
}
