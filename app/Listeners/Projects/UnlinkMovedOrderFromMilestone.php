<?php

namespace App\Listeners\Projects;

use App\Domain\Signals\Projects\MilestoneOrdersLinked;
use App\Domain\Signals\Projects\ServiceOrderMovedBetweenProjects;
use App\Domain\Signals\Signals;
use App\Models\ProjectMilestone;
use App\Models\ServiceOrder;

/**
 * A milestone only waits for werkbonnen of its own project. One that moves to
 * another project, or out of any, leaves the milestone it counted towards.
 *
 * The link is read from the database rather than from the werkbon in hand: links
 * are set in bulk, so an instance loaded earlier in the request can predate them.
 */
class UnlinkMovedOrderFromMilestone
{
    public function handle(ServiceOrderMovedBetweenProjects $signal): void
    {
        $order = $signal->service_order;
        $milestone = ProjectMilestone::find(ServiceOrder::whereKey($order->id)->value('project_milestone_id'));

        if (!$milestone || (int) $milestone->project_id === (int) $order->project_id) {
            return;
        }

        ServiceOrder::whereKey($order->id)->update(['project_milestone_id' => null]);

        Signals::dispatch(new MilestoneOrdersLinked($milestone, $milestone->project, collect(), collect([$order])));
    }
}
