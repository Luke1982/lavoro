<?php

namespace App\Listeners\Projects;

use App\Domain\Planning\Clock;
use App\Domain\Signals\Projects\MilestoneOrdersLinked;
use App\Domain\Signals\Projects\MilestoneReached;
use App\Domain\Signals\ServiceOrders\ServiceOrderStageChanged;
use App\Domain\Signals\Signals;
use App\Models\ProjectMilestone;
use App\Models\ServiceOrder;

/**
 * A milestone with werkbonnen on it is reached on the day the last of them closes.
 * It is never opened again by a werkbon that reopens: someone may have ticked it
 * off by hand for a reason the app does not know, and taking that back is theirs.
 */
class ReachMilestoneWhenItsOrdersClose
{
    public function handleOrderClosed(ServiceOrderStageChanged $signal): void
    {
        $milestone = ProjectMilestone::find(
            ServiceOrder::whereKey($signal->service_order->id)->value('project_milestone_id')
        );

        if ($milestone && $signal->new_stage?->closesOrder()) {
            $this->reachIfDone($milestone);
        }
    }

    public function handleOrdersLinked(MilestoneOrdersLinked $signal): void
    {
        $this->reachIfDone($signal->milestone);
    }

    private function reachIfDone(ProjectMilestone $milestone): void
    {
        if ($milestone->isReached()) {
            return;
        }

        $orders = $milestone->serviceOrders()->with('serviceOrderStage')->get();

        if ($orders->isEmpty() || $orders->contains(fn (ServiceOrder $order) => !$order->is_closed)) {
            return;
        }

        $milestone->update(['actual_date' => Clock::today()]);

        Signals::dispatch(new MilestoneReached(
            $milestone,
            $milestone->project,
            $orders->count() === 1 ? 'de gekoppelde werkbon is gesloten' : 'alle gekoppelde werkbonnen zijn gesloten',
        ));
    }
}
