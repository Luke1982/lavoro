<?php

namespace App\Domain\Signals\Projects;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ServiceOrder;
use Illuminate\Support\Collection;

/**
 * Werkbonnen were put on or taken off a milestone. Each one belongs on the
 * werkbon's own timeline too: on the werkbon is where someone asks why closing it
 * also ticked off a project step.
 */
class MilestoneOrdersLinked extends MilestoneSignal
{
    /**
     * @param  Collection<int, ServiceOrder>  $linked
     * @param  Collection<int, ServiceOrder>  $unlinked
     */
    public function __construct(
        ProjectMilestone $milestone,
        Project $project,
        public Collection $linked,
        public Collection $unlinked,
    ) {
        parent::__construct($milestone, $project);
    }

    public static function key(): string
    {
        return 'project.milestone_orders_linked';
    }

    public static function label(): string
    {
        return 'Werkbonnen bij mijlpaal';
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        $parts = array_filter([
            $this->linked->isEmpty() ? null : $this->numbers($this->linked) . ' gekoppeld',
            $this->unlinked->isEmpty() ? null : $this->numbers($this->unlinked) . ' losgekoppeld',
        ]);

        return $this->named() . ': ' . implode('; ', $parts);
    }

    public function activityContext(): array
    {
        return $this->linked->concat($this->unlinked)->all();
    }

    public function changes(): array
    {
        $title = $this->milestone->title;

        return $this->linked->map(fn (ServiceOrder $order) => $this->change($order, null, $title))
            ->concat($this->unlinked->map(fn (ServiceOrder $order) => $this->change($order, $title, null)))
            ->values()
            ->all();
    }

    private function change(ServiceOrder $order, ?string $old_label, ?string $new_label): array
    {
        return [
            'field' => 'project_milestone_id',
            'label' => 'Werkbon ' . $order->number . ': mijlpaal',
            'old_value' => $old_label === null ? null : (string) $this->milestone->getKey(),
            'new_value' => $new_label === null ? null : (string) $this->milestone->getKey(),
            'old_label' => $old_label,
            'new_label' => $new_label,
        ];
    }

    /** @param Collection<int, ServiceOrder> $orders */
    private function numbers(Collection $orders): string
    {
        return $orders->map->number->implode(', ');
    }
}
