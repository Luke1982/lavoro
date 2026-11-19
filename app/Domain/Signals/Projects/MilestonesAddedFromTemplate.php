<?php

namespace App\Domain\Signals\Projects;

use App\Domain\Signals\BaseSignal;
use App\Models\Project;
use App\Models\ProjectTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * A template put its milestones on a project in one go. One entry for the whole
 * set: that is the one thing the person did.
 */
class MilestonesAddedFromTemplate extends BaseSignal implements ProjectSignal
{
    /**
     * @param  array<int, string>  $titles
     */
    public function __construct(
        public Project $project,
        public ProjectTemplate $template,
        public array $titles,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'project.milestones_from_template';
    }

    public static function label(): string
    {
        return 'Mijlpalen uit sjabloon';
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
        return 'created';
    }

    public function activityDescription(): ?string
    {
        $count = count($this->titles);

        return ($count === 1 ? '1 mijlpaal' : $count . ' mijlpalen') . " toegevoegd uit sjabloon '"
            . $this->template->name . "': " . implode(', ', $this->titles);
    }

    public function activityMetadata(): ?array
    {
        return ['project_template_id' => $this->template->getKey(), 'titles' => $this->titles];
    }
}
