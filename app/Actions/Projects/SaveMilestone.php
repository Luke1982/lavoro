<?php

namespace App\Actions\Projects;

use App\Domain\Signals\Projects\MilestoneAdded;
use App\Domain\Signals\Projects\MilestoneChanged;
use App\Domain\Signals\Projects\MilestoneOrdersLinked;
use App\Domain\Signals\Projects\MilestoneReached;
use App\Domain\Signals\Projects\MilestoneRemoved;
use App\Domain\Signals\Projects\MilestoneReopened;
use App\Domain\Signals\Signals;
use App\Models\ProjectMilestone;
use App\Models\ServiceOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a milestone, each announced as the fact a person would name:
 * added, edited, reached, opened again, given its werkbonnen, removed.
 */
class SaveMilestone
{
    /** @param array<string, mixed> $data the validated fields */
    public function create(array $data): ProjectMilestone
    {
        return DB::transaction(function () use ($data) {
            $milestone = ProjectMilestone::create(Arr::except($data, 'service_order_ids'));

            Signals::dispatch(new MilestoneAdded($milestone, $milestone->project));

            if (array_key_exists('service_order_ids', $data)) {
                $this->linkOrders($milestone, $data['service_order_ids'] ?? []);
            }

            return $milestone;
        });
    }

    /** @param array<string, mixed> $data the validated fields, any subset */
    public function update(ProjectMilestone $milestone, array $data): void
    {
        DB::transaction(function () use ($milestone, $data) {
            $milestone->fill(Arr::except($data, 'service_order_ids'));

            if ($milestone->isDirty('projected_date')) {
                $milestone->overdue_notified_at = null;
            }

            $previous = array_intersect_key($milestone->getOriginal(), $milestone->getDirty());
            $was_reached = filled($milestone->getOriginal('actual_date'));

            $milestone->save();

            $reached_now = !$was_reached && $milestone->isReached();
            $reopened_now = $was_reached && !$milestone->isReached();

            $edited = Arr::only($previous, MilestoneChanged::fields());

            if ($reached_now || $reopened_now) {
                unset($edited['actual_date']);
            }

            if ($edited !== []) {
                Signals::dispatch(new MilestoneChanged($milestone, $milestone->project, $edited));
            }

            if ($reached_now) {
                Signals::dispatch(new MilestoneReached($milestone, $milestone->project));
            }

            if ($reopened_now) {
                Signals::dispatch(new MilestoneReopened($milestone, $milestone->project, (string) $previous['actual_date']));
            }

            if (array_key_exists('service_order_ids', $data)) {
                $this->linkOrders($milestone, $data['service_order_ids'] ?? []);
            }
        });
    }

    /**
     * The werkbonnen it waited for are let go first and said so, so their own
     * timelines show the link ending instead of it vanishing with the milestone.
     */
    public function delete(ProjectMilestone $milestone): void
    {
        DB::transaction(function () use ($milestone) {
            $project = $milestone->project;
            $this->release($milestone, $milestone->serviceOrders()->get());
            $milestone->delete();

            Signals::dispatch(new MilestoneRemoved($milestone, $project));
        });
    }

    /**
     * Makes the given werkbonnen of the project the ones this milestone waits for.
     * A werkbon counts towards one milestone, so picking it here takes it from the
     * milestone that had it, and that milestone hears it too: what it waits for
     * just changed, and it may have nothing open left.
     *
     * @param  array<int, int>  $order_ids
     */
    private function linkOrders(ProjectMilestone $milestone, array $order_ids): void
    {
        $current = $milestone->serviceOrders()->get();
        $wanted = ServiceOrder::where('project_id', $milestone->project_id)->whereKey($order_ids)->get();

        $linked = $wanted->reject(fn (ServiceOrder $order) => $current->contains($order));
        $unlinked = $current->reject(fn (ServiceOrder $order) => $wanted->contains($order));

        $linked->whereNotNull('project_milestone_id')
            ->groupBy('project_milestone_id')
            ->each(fn (Collection $taken, int $previous_id) => $this->release(ProjectMilestone::find($previous_id), $taken));

        if ($linked->isEmpty() && $unlinked->isEmpty()) {
            return;
        }

        ServiceOrder::whereKey($unlinked->modelKeys())->update(['project_milestone_id' => null]);
        ServiceOrder::whereKey($linked->modelKeys())->update(['project_milestone_id' => $milestone->id]);

        Signals::dispatch(new MilestoneOrdersLinked($milestone, $milestone->project, $linked->values(), $unlinked->values()));
    }

    /**
     * Lets these werkbonnen go from a milestone, and says so.
     *
     * @param  Collection<int, ServiceOrder>  $orders
     */
    private function release(?ProjectMilestone $milestone, Collection $orders): void
    {
        if (!$milestone || $orders->isEmpty()) {
            return;
        }

        ServiceOrder::whereKey($orders->modelKeys())->update(['project_milestone_id' => null]);

        Signals::dispatch(new MilestoneOrdersLinked($milestone, $milestone->project, collect(), $orders->values()));
    }
}
