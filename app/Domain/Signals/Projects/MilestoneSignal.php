<?php

namespace App\Domain\Signals\Projects;

use App\Domain\Signals\BaseSignal;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A fact about one milestone. A milestone has no page of its own: its history is
 * read on the project, so the project is the subject and the milestone travels in
 * the metadata and in the wording.
 */
abstract class MilestoneSignal extends BaseSignal implements ProjectSignal
{
    public function __construct(
        public ProjectMilestone $milestone,
        public Project $project,
    ) {
        parent::__construct();
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

    public function activityMetadata(): ?array
    {
        return [
            'milestone_id' => $this->milestone->getKey(),
            'milestone_title' => $this->milestone->title,
        ];
    }

    protected function named(): string
    {
        return "Mijlpaal '" . $this->milestone->title . "'";
    }

    protected static function readableDate(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('d-m-Y') : null;
    }
}
