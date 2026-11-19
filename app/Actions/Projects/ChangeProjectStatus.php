<?php

namespace App\Actions\Projects;

use App\Domain\Signals\Projects\ProjectClosedWithOpenOrders;
use App\Domain\Signals\Projects\ProjectStatusChanged;
use App\Domain\Signals\Signals;
use App\Enums\ProjectStatuses;
use App\Models\Project;
use App\Models\ServiceOrder;

/**
 * Moves a project to another status. Finishing one with werkbonnen still open is
 * allowed, but it is said out loud: the warning comes back for the screen and the
 * open werkbonnen go on the project's timeline.
 *
 * The status only moves from the one this request saw. Two monteurs starting on
 * the same project in the same second would otherwise both start it, and the
 * timeline would say so twice.
 */
class ChangeProjectStatus
{
    /** @return string|null the warning for the person, if there is one */
    public function execute(Project $project, ProjectStatuses $status, ?string $reason = null): ?string
    {
        $previous = $project->status;

        if ($previous === $status->value) {
            return null;
        }

        $moved = Project::whereKey($project->getKey())
            ->where('status', $previous)
            ->update(['status' => $status->value, 'updated_at' => now()]);

        if ($moved === 0) {
            return null;
        }

        $project->forceFill(['status' => $status->value])->syncOriginalAttribute('status');

        Signals::dispatch(new ProjectStatusChanged($project, $previous, $status->value, $reason));

        if ($status !== ProjectStatuses::afgerond) {
            return null;
        }

        $open_numbers = $project->serviceOrders()->with('serviceOrderStage')->orderBy('id')->get()
            ->reject(fn (ServiceOrder $order) => $order->is_closed)
            ->map(fn (ServiceOrder $order) => $order->number)
            ->values()
            ->all();

        if ($open_numbers === []) {
            return null;
        }

        $signal = new ProjectClosedWithOpenOrders($project, $open_numbers);
        Signals::dispatch($signal);

        return $signal->warning();
    }
}
