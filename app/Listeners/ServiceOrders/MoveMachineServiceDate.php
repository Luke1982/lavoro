<?php

namespace App\Listeners\ServiceOrders;

use App\Domain\Signals\ServiceOrders\ServiceJobCompleted;
use App\Domain\Signals\ServiceOrders\ServiceJobReopened;
use App\Models\ServiceJob;
use Carbon\Carbon;

/**
 * An approved keuring pushes the machine's next inspection date forward by its
 * certificate period, a temporary approval by the days it was given. A finished
 * keuring saved again with another outcome or other days first takes back what the
 * earlier outcome gave, so the date moves by the difference; saved again as it was,
 * it moves nothing. Opening the keuring again takes its days back off, so redoing
 * it does not count twice.
 *
 * Deleting a finished keuring leaves the date alone: a keuring from the past has
 * been overtaken by the ones done since, and taking its days off now would undo
 * theirs.
 */
class MoveMachineServiceDate
{
    public function handleCompleted(ServiceJobCompleted $signal): void
    {
        $service_job = $signal->service_job;
        $given_before = $signal->wasCompletedBefore()
            ? $service_job->daysToAdvanceFor($signal->previous_outcome, $signal->previous_days_temporary_approval)
            : null;
        $given_now = $service_job->getDaysToAdvanceNextServiceDate($service_job->days_temporary_approval);

        $this->move($service_job, ($given_now ?? 0) - ($given_before ?? 0));
    }

    public function handleReopened(ServiceJobReopened $signal): void
    {
        $service_job = $signal->service_job;
        $given = $service_job->getDaysToAdvanceNextServiceDate($service_job->days_temporary_approval);

        $this->move($service_job, -($given ?? 0));
    }

    private function move(ServiceJob $service_job, int $days): void
    {
        if ($days === 0) {
            return;
        }

        $machine = $service_job->asset;

        $machine->update([
            'next_service_date' => Carbon::parse($machine->next_service_date)->addDays($days)->toDateString(),
        ]);
    }
}
