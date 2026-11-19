<?php

namespace App\Domain\Signals\Projects;

use App\Models\Project;
use App\Models\ProjectMilestone;

class MilestoneReopened extends MilestoneSignal
{
    public function __construct(
        ProjectMilestone $milestone,
        Project $project,
        public string $previous_actual_date,
    ) {
        parent::__construct($milestone, $project);
    }

    public static function key(): string
    {
        return 'project.milestone_reopened';
    }

    public static function label(): string
    {
        return 'Mijlpaal weer open';
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        return $this->named() . ' weer opengezet, stond op behaald ' . self::readableDate($this->previous_actual_date);
    }

    public function changes(): array
    {
        return [[
            'field' => 'actual_date',
            'label' => $this->named() . ': behaald op',
            'old_value' => $this->previous_actual_date,
            'new_value' => null,
            'old_label' => self::readableDate($this->previous_actual_date),
            'new_label' => null,
        ]];
    }
}
