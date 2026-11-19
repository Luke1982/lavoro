<?php

namespace App\Domain\Signals\Projects;

use App\Domain\Signals\BaseSignal;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;

/**
 * A project moved to another status. The reason is set when the app moved it by
 * itself, so the trail says why a project started while nobody touched it.
 */
class ProjectStatusChanged extends BaseSignal implements ProjectSignal
{
    public function __construct(
        public Project $project,
        public ?string $previous_status,
        public string $new_status,
        public ?string $reason = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'project.status_changed';
    }

    public static function label(): string
    {
        return 'Projectstatus gewijzigd';
    }

    public static function coveredFields(): array
    {
        return ['status'];
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function subject(): Model
    {
        return $this->project;
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function activityDescription(): ?string
    {
        $sentence = 'Status van project ' . $this->project->label() . " gewijzigd van '"
            . ($this->previous_status ?? '-') . "' naar '" . $this->new_status . "'";

        return $this->reason === null ? $sentence : $sentence . ', ' . $this->reason;
    }

    /** A project starting or finishing is news at its customer too, where it is read with the project's name. */
    public function activityContext(): array
    {
        return array_values(array_filter([$this->project->customer]));
    }

    public function activityMetadata(): ?array
    {
        return $this->reason === null ? null : ['reason' => $this->reason];
    }

    public function changes(): array
    {
        return [[
            'field' => 'status',
            'label' => 'Status project ' . $this->project->number . ($this->reason === null ? '' : ' (' . $this->reason . ')'),
            'old_value' => $this->previous_status,
            'new_value' => $this->new_status,
            'old_label' => $this->previous_status,
            'new_label' => $this->new_status,
        ]];
    }
}
