<?php

namespace App\Domain\Signals\Projects;

/**
 * The day a milestone passed its planned date without being reached. Raised once
 * per milestone by the morning check, which is also what tells the people on it.
 */
class MilestoneOverdue extends MilestoneSignal
{
    public static function key(): string
    {
        return 'project.milestone_overdue';
    }

    public static function label(): string
    {
        return 'Mijlpaal over datum';
    }

    public function activityCategory(): string
    {
        return 'warning';
    }

    public function activityDescription(): ?string
    {
        return $this->named() . ' is over datum: gepland op '
            . self::readableDate($this->milestone->projected_date) . ' en nog niet behaald';
    }
}
