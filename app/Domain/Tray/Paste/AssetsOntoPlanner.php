<?php

namespace App\Domain\Tray\Paste;

use App\Actions\ServiceOrders\AddServiceJobsAction;
use App\Actions\ServiceOrders\CreateServiceOrderAction;
use App\Actions\ServiceOrders\NewServiceOrder;
use App\Domain\Tray\AssetFacts;
use App\Domain\Tray\TrayKinds;
use App\Models\Event;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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
        $customer = AssetFacts::sharedCustomer($paste->records);
        $count = $paste->records->count();

        return new Question(
            title: Str::ucfirst(TrayKinds::counted('asset', $count)) . ' inplannen',
            subtitle: $slot->subtitle(),
            items: AssetFacts::listed($paste->records),
            choices: [
                new Choice(
                    value: PlannerSlot::NEW_ORDER,
                    label: 'Nieuwe werkbon',
                    description: 'Voor ' . $customer->name . ', met een keuring voor '
                        . ($count === 1 ? 'de machine' : 'alle ' . $count . ' machines') . '.',
                    confirm: 'Werkbon maken en inplannen',
                ),
                ...$slot->openOrderChoices($customer),
            ],
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
            $order = app(CreateServiceOrderAction::class)->execute(new NewServiceOrder(
                customer_id: AssetFacts::sharedCustomer($paste->records)->id,
                location_id: AssetFacts::sharedLocationId($paste->records),
                description: AssetFacts::serviceOrderDescription($paste->records),
                asset_ids: $paste->records->pluck('id')->all(),
            ));
        } else {
            $order = $slot->chosenOrder();
            app(AddServiceJobsAction::class)->execute($order, $paste->records);
        }

        return $slot->plan($order);
    }
}
