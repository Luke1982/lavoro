<?php

namespace App\Actions\Tasks;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Tasks\TaskCheckChanged;
use App\Exceptions\Refusal;
use App\Models\ServiceOrderTaskInstanceCheck;
use App\Services\TaskChecks\TaskCheckLabels;

/**
 * Ticks a check on a werkbon task off, or back open, and puts that on the
 * timeline. Refused on a closed werkbon, a cancelled task or a completed one.
 *
 * Sending the state a check is already in changes nothing, so a second phone
 * with an old screen cannot claim someone else's tick.
 */
class TickTaskCheckAction
{
    public function __construct(private readonly TaskCheckLabels $labels) {}

    public function execute(ServiceOrderTaskInstanceCheck $check, bool $is_done): ServiceOrderTaskInstanceCheck
    {
        $check->loadMissing('instance.serviceOrder.serviceOrderStage');
        $instance = $check->instance;

        throw_if($instance->serviceOrder->is_closed, Refusal::class, 'De werkbon is gesloten.');
        throw_if($instance->is_cancelled, Refusal::class, 'Deze taak is geannuleerd.');
        throw_if($instance->is_complete, Refusal::class, 'Deze taak is al voltooid; heropen hem om checks te wijzigen.');

        if ($check->is_done === $is_done) {
            return $check;
        }

        $check->update([
            'is_done' => $is_done,
            'checked_at' => $is_done ? now() : null,
            'checked_by' => $is_done ? auth()->id() : null,
        ]);

        Signals::dispatch(new TaskCheckChanged(
            $instance->serviceOrder,
            $instance,
            $instance->timelineTitle(),
            $check,
            $this->labels->machine($instance, $check),
            $is_done,
        ));

        return $check;
    }
}
