<?php

namespace App\Domain\Tray\Paste;

use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\NewServiceOrder;
use App\Domain\Tray\AssetFacts;
use App\Domain\Tray\TrayKinds;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Machines dropped on a project: a new werkbon under that project, with a keuring
 * for each of them. A project holds its work as werkbonnen, not machines of its
 * own. Asked first, because it makes a werkbon.
 */
class AssetsOntoProject implements PasteHandler
{
    public function question(Paste $paste): Question
    {
        $project = $this->project($paste);
        $count = $paste->records->count();

        return new Question(
            title: Str::ucfirst(TrayKinds::counted('asset', $count)) . ' onder het project',
            subtitle: $project->title . ', ' . $this->customerFor($paste, $project)->name . '.',
            items: AssetFacts::listed($paste->records),
            choices: [new Choice(
                value: 'new',
                label: 'Nieuwe werkbon onder dit project',
                description: 'Met een keuring voor '
                    . ($count === 1 ? 'de machine' : 'alle ' . $count . ' machines') . '.',
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
        $gate->authorize('create', ServiceJob::class);

        $order = app(CreateServiceOrderAction::class)->execute(new NewServiceOrder(
            customer_id: $customer->id,
            project_id: $project->id,
            location_id: AssetFacts::sharedLocationId($paste->records),
            description: AssetFacts::serviceOrderDescription($paste->records),
            asset_ids: $paste->records->pluck('id')->all(),
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

    private function customerFor(Paste $paste, Project $project): Customer
    {
        $customer = AssetFacts::sharedCustomer($paste->records);

        if ($project->customer_id && (int) $project->customer_id !== $customer->id) {
            throw new Refusal(($paste->records->count() === 1 ? 'Deze machine hoort' : 'Deze machines horen')
                . ' bij ' . $customer->name . ', het project bij een andere klant.');
        }

        return $customer;
    }
}
