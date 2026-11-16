<?php

namespace App\Domain\Tray\Paste;

use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\NewServiceOrder;
use App\Domain\Tray\TicketFacts;
use App\Domain\Tray\TrayKinds;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Storingen dropped on a project: a new werkbon under that project, with the
 * storingen on it. Asked first, because it makes a werkbon.
 */
class TicketsOntoProject implements PasteHandler
{
    public function question(Paste $paste): Question
    {
        $project = $this->project($paste);
        $count = $paste->records->count();

        return new Question(
            title: Str::ucfirst(TrayKinds::counted('ticket', $count)) . ' onder het project',
            subtitle: $project->title . ', ' . $this->customerFor($paste, $project)->name . '.',
            items: TicketFacts::listed($paste->records),
            choices: [new Choice(
                value: 'new',
                label: 'Nieuwe werkbon onder dit project',
                description: 'Met ' . ($count === 1 ? 'de storing' : 'alle ' . $count . ' storingen') . ' erop.',
                confirm: 'Werkbon maken',
            )],
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $project = $this->project($paste);
        $customer = $this->customerFor($paste, $project);
        $gate = Gate::forUser($paste->user);

        $gate->authorize('create', ServiceOrder::class);
        $paste->records->each(fn (Ticket $ticket) => $gate->authorize('attachToServiceOrder', $ticket));

        $order = app(CreateServiceOrderAction::class)->execute(new NewServiceOrder(
            customer_id: $customer->id,
            project_id: $project->id,
            location_id: TicketFacts::sharedLocationId($paste->records),
            description: TicketFacts::serviceOrderDescription($paste->records),
            ticket_ids: $paste->records->pluck('id')->all(),
        ));

        return new PasteResult(
            'Werkbon ' . $order->number . ' staat nu onder ' . $project->title . '.',
            created: ['service_order_id' => $order->id],
        );
    }

    private function project(Paste $paste): Project
    {
        $project = Project::find($paste->target['id']);

        if (!$project || Gate::forUser($paste->user)->denies('view', $project)) {
            throw new Refusal('Dit project kun je niet openen.');
        }

        return $project;
    }

    /**
     * The customer the new werkbon is for: the storingen's own, which has to be
     * the project's as well. Storingen already on a werkbon are not free to take.
     */
    private function customerFor(Paste $paste, Project $project): Customer
    {
        $on_order = $paste->records->first(fn (Ticket $ticket) => $ticket->service_order_id !== null);

        if ($on_order) {
            throw new Refusal('"' . TicketFacts::title($on_order) . '" staat al op werkbon '
                . $on_order->serviceOrder?->number . '. Haal hem daar eerst af.');
        }

        $customer = TicketFacts::sharedCustomer($paste->records);

        if ($project->customer_id && (int) $project->customer_id !== $customer->id) {
            throw new Refusal(($paste->records->count() === 1 ? 'Deze storing hoort' : 'Deze storingen horen')
                . ' bij ' . $customer->name . ', het project bij een andere klant.');
        }

        return $customer;
    }
}
