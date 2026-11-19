<?php

namespace App\Actions\Projects;

use App\Models\ProjectTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * A template is saved whole: its name and its steps in the order they were given.
 * Projects already made from it keep the milestones they got; a template is a
 * starting point, not a link.
 */
class SaveProjectTemplate
{
    /** @param array{name: string, description?: ?string, milestones: array<int, array<string, mixed>>} $data */
    public function execute(ProjectTemplate $template, array $data): ProjectTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $template->fill(Arr::only($data, ['name', 'description']))->save();
            $template->milestones()->delete();

            foreach (array_values($data['milestones']) as $position => $step) {
                $template->milestones()->create([
                    'title' => $step['title'],
                    'description' => $step['description'] ?? null,
                    'offset_days' => (int) ($step['offset_days'] ?? 0),
                    'position' => $position,
                ]);
            }

            return $template;
        });
    }
}
