<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\ProjectTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    public function __construct(private AddMilestonesFromTemplate $add_milestones) {}

    /** @param array<string, mixed> $data the validated fields, with an optional project_template_id */
    public function execute(array $data): Project
    {
        return DB::transaction(function () use ($data) {
            $template_id = Arr::pull($data, 'project_template_id');
            $project = Project::create($data);

            if ($template_id) {
                $this->add_milestones->execute($project, ProjectTemplate::findOrFail($template_id));
            }

            return $project;
        });
    }
}
