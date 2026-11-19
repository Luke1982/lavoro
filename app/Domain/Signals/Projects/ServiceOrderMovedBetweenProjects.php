<?php

namespace App\Domain\Signals\Projects;

use App\Domain\Signals\BaseSignal;
use App\Models\Project;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * A werkbon was put under a project, moved to another, or taken out. This is the
 * projects' side of it: a project's timeline is where someone asks where a werkbon
 * went. The werkbon's own timeline already has the change through its automatic
 * trail, so this entry is not put on the werkbon a second time.
 */
class ServiceOrderMovedBetweenProjects extends BaseSignal implements ProjectSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?Project $previous_project,
        public ?Project $new_project,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'project.service_order_moved';
    }

    public static function label(): string
    {
        return 'Werkbon naar ander project';
    }

    public function project(): Project
    {
        return $this->new_project ?? $this->previous_project;
    }

    public function subject(): Model
    {
        return $this->project();
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        $order = 'Werkbon ' . $this->service_order->number;

        return match (true) {
            $this->previous_project === null => $order . " onder project '" . $this->new_project->label() . "' gezet",
            $this->new_project === null => $order . " uit project '" . $this->previous_project->label() . "' gehaald",
            default => $order . " verplaatst van project '" . $this->previous_project->label()
                . "' naar '" . $this->new_project->label() . "'",
        };
    }

    public function activityContext(): array
    {
        return $this->previous_project && $this->new_project ? [$this->previous_project] : [];
    }

    public function activityMetadata(): ?array
    {
        return ['service_order_id' => $this->service_order->getKey()];
    }

    public function changes(): array
    {
        return [[
            'field' => 'project_id',
            'label' => 'Werkbon ' . $this->service_order->number . ': project',
            'old_value' => $this->previous_project === null ? null : (string) $this->previous_project->getKey(),
            'new_value' => $this->new_project === null ? null : (string) $this->new_project->getKey(),
            'old_label' => $this->previous_project?->label(),
            'new_label' => $this->new_project?->label(),
        ]];
    }
}
