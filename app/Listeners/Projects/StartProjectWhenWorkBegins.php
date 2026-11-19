<?php

namespace App\Listeners\Projects;

use App\Actions\Projects\ChangeProjectStatus;
use App\Domain\Signals\Appointments\ExecutionStarted;
use App\Domain\Signals\Appointments\ExecutionTimesRegistered;
use App\Domain\Signals\ServiceOrders\ServiceOrderStageChanged;
use App\Enums\ProjectStatuses;
use App\Models\ServiceOrder;

/**
 * A project is started the moment work on it starts: a monteur begins on one of
 * its werkbonnen, registers times on it, or a werkbon of it is closed. Only a
 * project that has not started yet moves; a finished one is not reopened by work
 * that comes in late.
 */
class StartProjectWhenWorkBegins
{
    public function __construct(private ChangeProjectStatus $change_status) {}

    public function handleExecutionStarted(ExecutionStarted $signal): void
    {
        $signal->event->serviceOrders->each(fn (ServiceOrder $order) => $this->start($order));
    }

    public function handleTimesRegistered(ExecutionTimesRegistered $signal): void
    {
        $signal->event->serviceOrders->each(fn (ServiceOrder $order) => $this->start($order));
    }

    public function handleOrderClosed(ServiceOrderStageChanged $signal): void
    {
        if ($signal->new_stage?->closesOrder()) {
            $this->start($signal->service_order);
        }
    }

    private function start(ServiceOrder $order): void
    {
        $project = $order->project;

        if ($project?->statusEnum() !== ProjectStatuses::niet_gestart) {
            return;
        }

        $this->change_status->execute(
            $project,
            ProjectStatuses::gestart,
            'werk begonnen op werkbon ' . $order->number,
        );
    }
}
