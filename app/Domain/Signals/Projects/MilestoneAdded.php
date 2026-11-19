<?php

namespace App\Domain\Signals\Projects;

class MilestoneAdded extends MilestoneSignal
{
    public static function key(): string
    {
        return 'project.milestone_added';
    }

    public static function label(): string
    {
        return 'Mijlpaal toegevoegd';
    }

    public function activityCategory(): string
    {
        return 'created';
    }

    public function activityDescription(): ?string
    {
        $planned = self::readableDate($this->milestone->projected_date);
        $reached = self::readableDate($this->milestone->actual_date);

        return $this->named() . ' toegevoegd'
            . ($planned ? ', gepland op ' . $planned : '')
            . ($reached ? ', behaald op ' . $reached : '');
    }
}
