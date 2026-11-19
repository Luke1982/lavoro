<?php

namespace App\Domain\Signals\Projects;

class MilestoneRemoved extends MilestoneSignal
{
    public static function key(): string
    {
        return 'project.milestone_removed';
    }

    public static function label(): string
    {
        return 'Mijlpaal verwijderd';
    }

    public function activityCategory(): string
    {
        return 'warning';
    }

    public function activityDescription(): ?string
    {
        return $this->named() . ' verwijderd';
    }
}
