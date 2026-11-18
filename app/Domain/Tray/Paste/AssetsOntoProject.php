<?php

namespace App\Domain\Tray\Paste;

use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Domain\Tray\Kinds\AssetKind;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Gate;

/**
 * Machines dropped on a project: a new werkbon under that project, with a keuring
 * for each of them. A project holds its work as werkbonnen, not machines of its
 * own. Asked first, because it makes a werkbon.
 */
class AssetsOntoProject implements PasteHandler
{
    public function question(Paste $paste): Question
    {
        $customer = AssetKind::sharedCustomer($paste->records);
        $project = DropTarget::projectFor($paste, $customer);

        return new Question(
            title: $paste->heading() . ' onder het project',
            subtitle: $project->title . ', ' . $customer->name . '.',
            items: AssetKind::listed($paste->records),
            choices: [new Choice(
                value: 'new',
                label: 'Nieuwe werkbon onder dit project',
                description: 'Met een keuring voor ' . $paste->every() . '.',
                confirm: 'Werkbon maken',
            )],
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $project = DropTarget::projectFor($paste, AssetKind::sharedCustomer($paste->records));
        $gate = Gate::forUser($paste->user);

        $gate->authorize('create', ServiceOrder::class);
        $gate->authorize('create', ServiceJob::class);

        $order = app(CreateServiceOrderAction::class)
            ->execute(AssetKind::newServiceOrder($paste->records, $project->id));

        return new PasteResult(
            'Werkbon ' . $order->number . ' staat nu onder ' . $project->title . '.',
            created: ['service_order_ids' => [$order->id]],
        );
    }
}
