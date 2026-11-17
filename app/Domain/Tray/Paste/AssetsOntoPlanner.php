<?php

namespace App\Domain\Tray\Paste;

use App\Actions\ServiceOrders\AddServiceJobsAction;
use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Domain\Tray\Kinds\AssetKind;
use App\Models\Event;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Gate;

/**
 * Machines dropped on a technician's day in the planner: a werkbon with a keuring
 * for each of them, planned on that spot. Always asked first, because there is a
 * choice between a new werkbon and one of the customer's open ones.
 */
class AssetsOntoPlanner implements PasteHandler
{
    public function question(Paste $paste): Question
    {
        $slot = new PlannerSlot($paste);

        return new Question(
            title: $paste->heading() . ' inplannen',
            subtitle: $slot->subtitle(),
            items: AssetKind::listed($paste->records),
            choices: $slot->choices(
                AssetKind::sharedCustomer($paste->records),
                'met een keuring voor ' . $paste->every(),
            ),
            legend: 'Op welke werkbon?',
        );
    }

    public function perform(Paste $paste): PasteResult
    {
        $slot = new PlannerSlot($paste);
        $gate = Gate::forUser($paste->user);
        $gate->authorize('create', Event::class);
        $gate->authorize('create', ServiceJob::class);

        if ($slot->wantsNewOrder()) {
            $gate->authorize('create', ServiceOrder::class);
            $order = app(CreateServiceOrderAction::class)->execute(AssetKind::newServiceOrder($paste->records));
        } else {
            $order = $slot->chosenOrder();
            app(AddServiceJobsAction::class)->execute($order, $paste->records);
        }

        return $slot->plan($order);
    }
}
