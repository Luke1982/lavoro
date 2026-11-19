<?php

namespace App\Domain\Signals\Projects;

use App\Domain\Planning\Clock;
use App\Models\Project;
use App\Models\ProjectMilestone;

/**
 * A milestone was reached: ticked off by hand, or by the app the moment the last
 * werkbon linked to it closed. The reason says which.
 */
class MilestoneReached extends MilestoneSignal
{
    public function __construct(
        ProjectMilestone $milestone,
        Project $project,
        public ?string $reason = null,
    ) {
        parent::__construct($milestone, $project);
    }

    public static function key(): string
    {
        return 'project.milestone_reached';
    }

    public static function label(): string
    {
        return 'Mijlpaal behaald';
    }

    public function activityDescription(): ?string
    {
        $days_late = $this->milestone->lateness(Clock::todayAsDate())['days_late'];

        return $this->named() . ' behaald op ' . self::readableDate($this->milestone->actual_date)
            . ($days_late ? ' (' . $days_late . ($days_late === 1 ? ' dag' : ' dagen') . ' te laat)' : '')
            . ($this->reason ? ', ' . $this->reason : '');
    }

    public function activityMetadata(): ?array
    {
        return [...parent::activityMetadata(), 'reason' => $this->reason];
    }

    public function changes(): array
    {
        return [[
            'field' => 'actual_date',
            'label' => $this->named() . ': behaald op' . ($this->reason ? ' (' . $this->reason . ')' : ''),
            'old_value' => null,
            'new_value' => (string) $this->milestone->actual_date,
            'old_label' => null,
            'new_label' => self::readableDate($this->milestone->actual_date),
        ]];
    }
}
