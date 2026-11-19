<?php

namespace App\Actions\Projects;

use App\Exceptions\Refusal;
use App\Models\Project;

/**
 * Only an empty project can go. One with werkbonnen is history: deleting it would
 * leave those werkbonnen without the project they were done for. Cancelling says
 * the same thing and keeps it.
 */
class DeleteProject
{
    public function execute(Project $project): void
    {
        $orders = $project->serviceOrders()->count();

        if ($orders > 0) {
            throw new Refusal('Project ' . $project->label() . ' heeft ' . ($orders === 1 ? '1 werkbon' : $orders . ' werkbonnen')
                . ' en kan daarom niet worden verwijderd. Zet het op Geannuleerd als het niet doorgaat.');
        }

        $project->delete();
    }
}
